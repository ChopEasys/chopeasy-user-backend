<?php

namespace App\Services\User;

use App\Helpers\ImageKitHelper;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use App\Repositories\User\UserInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use App\Notifications\VerifyEmail;
use App\Responser\JsonResponser;
use App\Services\GeoLocationService;
use App\Services\ProductReviewed\RecentlyViewedService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserService
{
    protected $userInterface;
    protected $geoLocationService;


    public function __construct(UserInterface $userInterface, GeoLocationService $geoLocationService)
    {
        $this->userInterface = $userInterface;
        $this->geoLocationService = $geoLocationService;
    }

    protected function bankRelationName(?string $userType): ?string
    {
        return match ($userType) {
            'agent' => 'agentBankDetails',
            'vendor' => 'vendorBankDetails',
            'rider' => 'riderBankDetails',
            default => null,
        };
    }

    public function register(array $data): User
    {
        $otp = rand(1000, 9999);
        $data['email_otp'] = $otp;
        $data['otp_expires_at'] = now()->addMinutes(10);

        // Vendor-specific processing
        if (($data['user_type'] ?? null) === 'vendor') {

            if (!empty($data['store_image'])) {
                $data['store_image'] = ImageKitHelper::uploadBase64Image(
                    $data['store_image'],
                    'vendor/store'
                );
            } else {
                unset($data['store_image']);
            }
        
            if (!empty($data['cac_certificate'])) {
                $data['cac_certificate'] = ImageKitHelper::uploadBase64Image(
                    $data['cac_certificate'],
                    'vendor/cac'
                );
            } else {
                unset($data['cac_certificate']);
            }
        
            if (empty($data['latitude']) || empty($data['longitude'])) {
                if (!empty($data['address'])) {
                    [$lat, $lng] = $this->geoLocationService->getCoordinatesFromAddress($data['address']);
                    $data['latitude'] = $lat ?? null;
                    $data['longitude'] = $lng ?? null;
                }
            }
        }

        // Rider-specific geolocation
        if (($data['user_type'] ?? null) === 'rider') {
            // Get rider coordinates if not provided in payload
            if (empty($data['latitude']) || empty($data['longitude'])) {
                if (!empty($data['address'])) {
                    [$lat, $lng] = $this->geoLocationService->getCoordinatesFromAddress($data['address']);
                    $data['latitude'] = $lat ?? $data['latitude'] ?? null;
                    $data['longitude'] = $lng ?? $data['longitude'] ?? null;
                }
            }
        }

        if (in_array(($data['user_type'] ?? null), ['vendor', 'rider'], true)) {
            $data['is_active'] = false;
        }

        // Customer geolocation - use provided coordinates or geocode from address
        if (($data['user_type'] ?? null) === 'customer') {
            // Get customer coordinates if not provided in payload
            if (empty($data['latitude']) || empty($data['longitude'])) {
                if (!empty($data['address'])) {
                    [$lat, $lng] = $this->geoLocationService->getCoordinatesFromAddress($data['address']);
                    $data['latitude'] = $lat ?? $data['latitude'] ?? null;
                    $data['longitude'] = $lng ?? $data['longitude'] ?? null;
                }
            }
        }

        // Bank details (do not pass to user create)
        $bankRelationName = $this->bankRelationName($data['user_type'] ?? null);
        $bankData = null;
        if ($bankRelationName) {
            $bankData = [
                'bank_name' => $data['bank_name'] ?? null,
                'bank_code' => $data['bank_code'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'account_name' => $data['account_name'] ?? null,
            ];
            unset($data['bank_name'], $data['bank_code'], $data['account_number'], $data['account_name']);
        }

        $referralCode = $data['referral_code'] ?? null;
        unset($data['referral_code']);
        if ($referralCode && in_array(($data['user_type'] ?? null), ['customer', 'vendor', 'rider'], true)) {
            $agent = User::where('id', $referralCode)->where('user_type', 'agent')->first();
            if ($agent) {
                $data['referred_by_agent_id'] = $agent->id;
            }
        }

        // if (in_array($data['user_type'] ?? null, ['customer', 'rider'], true)) {
        //     $lat = $data['latitude'] ?? null;
        //     $lon = $data['longitude'] ?? null;

        //     if ($lat && $lon) {
        //         $radiusKm = 10;
        //         $nearbyVendor = DB::table('users')
        //             ->where('user_type', 'vendor')
        //             ->whereNotNull('latitude')
        //             ->whereNotNull('longitude')
        //             ->selectRaw("
        //         ( 6371 * acos(
        //             cos(radians(?)) * cos(radians(latitude))
        //             * cos(radians(longitude) - radians(?))
        //             + sin(radians(?)) * sin(radians(latitude))
        //         )) AS distance
        //     ", [$lat, $lon, $lat])
        //             ->having('distance', '<=', $radiusKm)
        //             ->orderByRaw('distance ASC')
        //             ->first();

        //         if (!$nearbyVendor) {
        //             throw new \Exception("We don't cover your location yet. No vendors within {$radiusKm}km.");
        //         }
        //     }
        // }

        // Create user
        $user = $this->userInterface->create($data);

        if ($bankData && $bankRelationName) {
            $filledBankFields = array_filter(
                $bankData,
                fn($value) => !is_null($value) && $value !== ''
            );

            if (count($filledBankFields) === 4) {
                $user->{$bankRelationName}()->create($bankData);

                if ($bankRelationName !== 'agentBankDetails') {
                    $user->agentBankDetails()->delete();
                }
            }
        }

        // Save vendor profile if vendor
        // if ($data['user_type'] === 'vendor') {
        //     $user->vendorProfile()->create([
        //         'vendor_id' => $user->id,
        //         'latitude' => $data['latitude'] ?? null,
        //         'longitude' => $data['longitude'] ?? null,
        //         'description' => $data['description'] ?? null,
        //         'store_type' => $data['store_type'] ?? null,
        //         'delivery_time' => $data['delivery_time'] ?? null,
        //         'logo' => $data['store_image'] ?? null,
        //     ]);
        // }

        try {
            $user->notify(new EmailOtpNotification($user, $otp));
        } catch (\Throwable $e) {
            // Log::error('OTP Email Send Failed: ' . $e->getMessage());
        }

        // Assign role
        if ($data['user_type'] === 'rider') {
            $user->assignRole('Customer');
        } elseif ($data['user_type'] === 'vendor') {
            $user->assignRole('Customer');
        } else {
            $user->assignRole('Customer');
        }

        return $user;
    }

    public function login(Request $request, array $credentials)
    {
        $sessionId = $request->cookie('cart_session_id')
            ?? $request->header('X-Session-ID')
            ?? $request->input('session_id');

        if (is_string($sessionId)) {
            $sessionId = trim($sessionId);
            if ($sessionId === '') {
                $sessionId = null;
            }
        }

        if (!$token = Auth::guard('api')->attempt($credentials)) {
            return response()->json([
                'error' => true,
                'message' => 'Invalid credentials',
                'status' => 401,
                'data' => null
            ], 401);
        }

        $user = Auth::guard('api')->user();

        if ($sessionId) {
            DB::transaction(function () use ($user, $sessionId) {
                $this->mergeGuestSessionToUser($user, $sessionId);
                app(RecentlyViewedService::class)->mergeSessionViewsToUser($sessionId, $user->id);
            });
        }

        $this->mergeGuestOrdersByEmail($user);
        $this->mergeGuestShippingAddressByEmail($user);

        if (!$user->is_verified || !$user->can_login) {
            return response()->json([
                'error' => true,
                'message' => 'Please verify your email before logging in.',
                'status' => 403,
                'data' => null
            ], 403);
        }

        if (in_array($user->user_type, ['vendor', 'rider'], true) && !$user->is_active) {

            $message = $user->user_type === 'vendor'
                ? "Welcome to ChopEasy! 🎉 Your vendor application is being reviewed. A ChopEasy representative will visit your store for verification. We'll notify you as soon as your account is approved and ready to go."
                : "Welcome to ChopEasy! 🎉 Your rider application is being reviewed. Our team is currently verifying your information. We'll notify you as soon as your account is approved and ready to start delivering.";

            return response()->json([
                'error' => true,
                'pending_verification' => true,
                'user_type' => $user->user_type,
                'message' => $message,
                'status' => 403,
                'data' => null,
            ], 403);
        }

        // Update login metadata
        $user->update([
            'last_login' => now(),
            'ip_address' => $request->ip(),
        ]);

        $roles = $user->getRoleNames()->toArray();

        return JsonResponser::send(false, 'Login successful.', [
            'token' => $token,
            'user'  => $user,
            'roles' => $roles,
        ], 200)->withCookie(cookie()->forget('cart_session_id'));
    }



    public function verifyEmail(int $id, string $hash)
    {
        $user = $this->userInterface->find($id);
        if (!$user || !hash_equals($hash, sha1($user->email))) {
            return ['error' => 'Invalid verification link', 'status' => 400];
        }

        if ($user->is_verified) {
            return ['message' => 'Email already verified'];
        }

        $this->userInterface->markEmailAsVerified($id);
        return ['message' => 'Email verified'];
    }

    public function sendPasswordResetLink(string $email)
    {
        $status = Password::sendResetLink(['email' => $email]);

        if ($status === Password::RESET_LINK_SENT) {
            return [
                'error' => false,
                'message' => 'Password reset link sent successfully.',
                'status' => 200,
            ];
        }

        return [
            'error' => true,
            'message' => __($status),
            'status' => 400,
        ];
    }

    public function resendOtp(string $email)
    {
        $status = Password::sendResetLink(['email' => $email]);

        if ($status === Password::RESET_LINK_SENT) {
            return [
                'error' => false,
                'message' => 'Password reset link sent successfully.',
                'status' => 200,
            ];
        }

        return [
            'error' => true,
            'message' => __($status),
            'status' => 400,
        ];
    }


    public function resetPassword(array $data): array
    {
        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            return [
                'error' => true,
                'message' => 'User not found.',
                'status' => 404,
            ];
        }

        if (
            $user->email_otp !== $data['token'] ||
            !$user->otp_expires_at ||
            now()->greaterThan($user->otp_expires_at)
        ) {
            return [
                'error' => true,
                'message' => 'Invalid or expired OTP.',
                'status' => 422,
            ];
        }

        // Reset password
        $user->password = bcrypt($data['password']);
        $user->email_otp = null;
        $user->otp_expires_at = null;
        $user->save();

        return [
            'error' => false,
            'message' => 'Password reset successful.',
            'status' => 200,
        ];
    }


    // public function mergeGuestSessionToUser(User $user, string $sessionId)
    // {
    //     $guestCartItems = Cart::where('session_id', $sessionId)->get();
    //     foreach ($guestCartItems as $guestItem) {
    //         $existingItem = Cart::where('user_id', $user->id)
    //             ->where('product_id', $guestItem->product_id)
    //             ->whereNull('product_variant_id')
    //             ->first();

    //         if ($existingItem) {
    //             $existingItem->quantity += $guestItem->quantity;
    //             $existingItem->save();
    //             $guestItem->delete();
    //         } else {
    //             $guestItem->update([
    //                 'user_id' => $user->id,
    //                 'session_id' => null,
    //             ]);
    //         }
    //     }

    //     Order::where('session_id', $sessionId)->update([
    //         'user_id' => $user->id,
    //         'session_id' => null,
    //     ]);

    //     Cookie::queue(Cookie::forget('cart_session_id'));
    // }

    public function mergeGuestSessionToUser(User $user, string $sessionId)
    {
        $guestCartItems = Cart::where('session_id', $sessionId)->get();

        foreach ($guestCartItems as $guestItem) {

            $existingItemQuery = Cart::where('user_id', $user->id)
                ->where('product_id', $guestItem->product_id);

            if (is_null($guestItem->product_variant_id)) {
                $existingItemQuery->whereNull('product_variant_id');
            } else {
                $existingItemQuery->where('product_variant_id', $guestItem->product_variant_id);
            }

            $existingItem = $existingItemQuery->first();

            if ($existingItem) {
                $existingItem->quantity += $guestItem->quantity;
                $existingItem->total_cost =
                    $existingItem->quantity * $existingItem->price_at_addition;
                $existingItem->save();

                $guestItem->delete();
            } else {
                $guestItem->update([
                    'user_id' => $user->id,
                    'session_id' => null,
                ]);
            }
        }

        Order::where('session_id', $sessionId)->update([
            'user_id' => $user->id,
            'session_id' => null,
        ]);

        // Final cleanup: after merge, no cart row should still carry this guest session ID.
        Cart::where('session_id', $sessionId)
            ->whereNull('user_id')
            ->update([
                'user_id' => $user->id,
                'session_id' => null,
            ]);

        // Defensive cleanup for any unexpected leftovers tied to this session.
        Cart::where('session_id', $sessionId)->delete();
    }


    private function mergeGuestOrdersByEmail(User $user)
    {
        Order::whereNull('user_id')
            ->whereNotNull('session_id')
            ->where('shipping_address_snapshot->email', $user->email)
            ->update([
                'user_id' => $user->id,
                'session_id' => null,
            ]);
    }

    private function mergeGuestShippingAddressByEmail(User $user)
    {
        $guestAddress = \App\Models\ShippingAddress::whereNull('user_id')
            ->whereNotNull('session_id')
            ->where('email', $user->email)
            ->latest()
            ->first();

        if ($guestAddress) {
            $newAddress = $guestAddress->replicate();
            $newAddress->user_id = $user->id;
            $newAddress->session_id = null;
            $newAddress->is_default = true;
            $newAddress->save();
        }
    }
}
