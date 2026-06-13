<?php

namespace App\Http\Controllers\v1\Auth;

use App\Helpers\GeneralHelper;
use App\Helpers\ImageKitHelper;
use App\Http\Controllers\Controller;
use App\Services\User\UserService;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Models\VendorProfile;
use App\Notifications\EmailOtpNotification;
use App\Responser\JsonResponser;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function register(RegisterRequest $request)
    {
        DB::beginTransaction();
        try {
            $user = $this->userService->register($request->validated());
            $dataToLog = [
                'causer_id' => $user->id,
                'action_id' => $user->id,
                'action_type' => "App\Models\User",
                'log_name' => "User registered successfully and email sent for verification",
                'description' => "{$user['firstname']} {$user['lastname']} added successfully",
            ];

            GeneralHelper::storeAuditLog($dataToLog);
            DB::commit();
            return JsonResponser::send(
                false,
                'Registration successful. Please check your email to confirm your account.',
                $user,
                201
            );
        } catch (Exception $e) {
            // Log::info($e);
            DB::rollBack();
            return JsonResponser::send(true, "An error occurred during registration. Please try again later" . $e->getMessage(), [], 500);
        }
    }

    public function login(LoginRequest $request)
    {
        try {
            return $this->userService->login($request, $request->only('email', 'password'));
        } catch (Exception $e) {
            return JsonResponser::send(
                true,
                'An error occurred during login. Please try again later.',
                null,
                500
            );
        }
    }

    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return JsonResponser::send(
                false,
                'Successfully logged out',
                null,
                200
            );
        } catch (\Exception $e) {
            return JsonResponser::send(
                true,
                'Failed to logout. Please try again.',
                null,
                500
            );
        }
    }

    public function getUser(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return JsonResponser::send(
                    true,
                    'No authenticated user found',
                    null,
                    401
                );
            }

            return JsonResponser::send(
                false,
                'Authenticated user retrieved successfully.',
                $user, // ✅ return raw user model
                200
            );
        } catch (\Exception $e) {
            return JsonResponser::send(
                true,
                'An error occurred while fetching user details.',
                null,
                500
            );
        }
    }


    public function update(Request $request)
    {
        $request->validate([
            'firstname' => 'nullable|string',
            'lastname' => 'nullable|string',
            'email' => 'nullable|email|unique:users,email,' . Auth::id(),
        ]);

        $user = Auth::user();
        $user->update($request->only(['firstname', 'lastname', 'email']));

        return response()->json(['message' => 'User updated', 'user' => new UserResource($user)]);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|digits:4',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return JsonResponser::send(true, 'Invalid email address.', null, 404);
        }

        if ($user->email_otp !== $request->otp) {
            return JsonResponser::send(true, 'Invalid OTP provided.', null, 422);
        }

        if ($user->otp_expires_at === null || now()->greaterThan($user->otp_expires_at)) {
            return JsonResponser::send(true, 'OTP has expired. Please request a new one.', null, 422);
        }

        $user->update([
            'is_verified' => true,
            'can_login' => true,
            'email_verified_at' => now(),
            'email_otp' => null,
            'otp_expires_at' => null,
        ]);

        return JsonResponser::send(false, 'Email verified successfully.', [
            'user' => ['email' => $user->email],
        ]);
    }


    public function resendOtp(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return JsonResponser::send(true, 'User not found', null, 404);
        }

        $otp = rand(1000, 9999);

        $user->update([
            'email_otp' => $otp,
            'otp_expires_at' => Carbon::now()->addMinutes(10),
        ]);

        $user->notify(new EmailOtpNotification($user, $otp));

        return JsonResponser::send(false, 'OTP resent successfully.');
    }

    public function getAllUsersForAdmin(Request $request)
    {
        $perPage = $request->query('per_page', 10);
        $search = $request->query('search');
        $status = $request->query('status');
        $isActive = $request->query('is_active');
        $minOrders = $request->query('min_orders');
        $maxOrders = $request->query('max_orders');

        $usersQuery = User::query()
            ->withCount('orders')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('fullname', 'like', "%$search%")
                        ->orWhere('email', 'like', "%$search%")
                        ->orWhere('store_name', 'like', "%$search%");
                });
            })
            ->when($status, function ($query, $status) {
                if ($status === 'verified') {
                    $query->where('is_verified', true);
                } elseif ($status === 'unverified') {
                    $query->where('is_verified', false);
                }
            })
            ->when($isActive !== null, fn($q) => $q->where('is_active', $isActive))
            ->when($minOrders, fn($q) => $q->having('orders_count', '>=', $minOrders))
            ->when($maxOrders, fn($q) => $q->having('orders_count', '<=', $maxOrders))
            ->orderByDesc('created_at');

        $users = $usersQuery->paginate($perPage);

        $formatted = $users->map(function ($user) {
            $role = ucfirst($user->user_type ?? 'customer');
            $status = $user->is_active ? 'active' : 'blocked';
            return [
                'id' => (string) $user->id,
                'name' => $user->fullname ?? $user->email,
                'email' => $user->email,
                'role' => $role,
                'status' => $status,
                'created_at' => $user->created_at->format('Y-m-d'),
                'orders_count' => $user->orders_count,
                'is_verified' => $user->is_verified ? 'Verified' : 'Unverified',
                'is_active' => $user->is_active,
            ];
        });

        return response()->json([
            'data' => $formatted,
            'pagination' => [
                'currentPage' => $users->currentPage(),
                'lastPage' => $users->lastPage(),
                'perPage' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function getSingleUserForAdmin($id)
    {
        try {
            $user = User::with([
                'orders' => fn($q) => $q->with('items')->latest(),
            ])->withCount('orders')->findOrFail($id);

            $totalSpent = $user->orders->sum('total_amount');
            $avgOrderValue = $user->orders->avg('total_amount');
            $totalItems = $user->orders->reduce(function ($sum, $order) {
                $items = $order->item_count;
                if ($items === null) {
                    $items = $order->items ? $order->items->sum('quantity') : 0;
                }
                return $sum + (int) $items;
            }, 0);
            $lastOrder = $user->orders->first();
            $lastOrderDate = optional($lastOrder)->created_at;
            // $customerId = '' . str_pad($user->id, 4, '0', STR_PAD_LEFT);

            $address = [
                'company' => null,
                'line1' => $user->address ?? null,
                'line2' => null,
                'city' => $user->lga ?? null,
                'state' => $user->state ?? null,
                'country' => $user->country ?? null,
            ];
            $hasAddress = collect($address)->filter(fn($val) => !empty($val))->isNotEmpty();

            return response()->json([
                'customer' => [
                    // 'id' => $user->id,
                    'customer_id' => $user->id,
                    'address' => $user->address,
                    'full_name' => $user->fullname ?? $user->email,
                    'email' => $user->email,
                    'phone' => $user->phoneno,
                    'verified' => $user->is_verified ? 'Verified' : 'Unverified',
                    'joined_at' => $user->created_at->format('F d, Y'),
                    'note' => $user->note ?? "No notes available.",
                ],
                'metrics' => [
                    'total_orders' => $user->orders_count,
                    'total_items' => (int) $totalItems,
                    'total_spent' => $totalSpent,
                    'avg_order_value' => round($avgOrderValue, 2),
                    'last_order_date' => $lastOrderDate?->format('M d, Y'),
                    'last_order_days_ago' => $lastOrderDate ? $lastOrderDate->diffForHumans() : null,
                ],
                'orders' => $user->orders->map(function ($order) {
                    return [
                        'id'   => $order->id,
                        'order_id' => $order->order_number,
                        'date' => $order->created_at->format('M d, Y'),
                        'amount' => $order->total_amount,
                        'status' => $order->status,
                        'items_count' => (int) ($order->item_count ?? $order->items?->sum('quantity') ?? 0),
                    ];
                }),
                'address' => $hasAddress ? $address : null,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => true,
                'message' => "User not found",
            ], 404);
        }
    }

   public function updateProfile(Request $request)
{
    $user = Auth::user();

    if (!$user) {
        return response()->json(['error' => 'User not found'], 404);
    }

    // Password update
    if ($request->filled('oldPassword') && $request->filled('newPassword')) {
        if (!Hash::check($request->oldPassword, $user->password)) {
            return response()->json(['error' => 'Old password is incorrect'], 422);
        }
        $user->password = Hash::make($request->newPassword);
    }

    // Common fields
    if ($request->filled('fullname')) {
        $user->fullname = $request->fullname;
    }
    if ($request->filled('phone')) {
        $user->phoneno = $request->phone;
    }
    if ($request->filled('email')) {
        $user->email = $request->email;
    }

    switch ($user->user_type) {
        case 'vendor':
            if ($request->filled('store_name')) {
                $user->store_name = $request->store_name;
            }
            if ($request->filled('business_name')) {
                $user->business_name = $request->business_name;
            }
            if ($request->filled('business_type')) {
                $user->business_type = $request->business_type;
            }
            if ($request->filled('business_registered')) {
                $user->business_registered = $request->business_registered;
            }
            if ($request->filled('business_owner')) {
                $user->business_owner = $request->business_owner;
            }
            if ($request->has('is_not_owner')) {
                $user->is_not_owner = $request->is_not_owner;
            }
            if ($request->filled('latitude')) {
                $user->latitude = $request->latitude;
            }
            if ($request->filled('longitude')) {
                $user->longitude = $request->longitude;
            }
            if ($request->filled('cacNumber')) {
                $user->cac_certificate = $request->cacNumber;
            }
            if ($request->filled('storeAddress')) {
                $user->address = $request->storeAddress;
            }
            if ($request->filled('openingTime')) {
                $user->opening_time = $request->openingTime;
            }
            if ($request->filled('closingTime')) {
                $user->closing_time = $request->closingTime;
            }
            if ($request->filled('storePhoto')) {
                $user->store_image = ImageKitHelper::uploadFile(
                    $request->storePhoto,
                    'vendor_store_' . $user->id . '_' . time()
                );
            }
            if ($request->filled('userImage')) {
                $user->image = ImageKitHelper::uploadFile(
                    $request->userImage,
                    'vendor_profile_' . $user->id . '_' . time()
                );
            }
            break;

        case 'customer':
            if ($request->filled('storeAddress')) {
                $user->address = $request->storeAddress;
            }
            if ($request->filled('latitude')) {
                $user->latitude = $request->latitude;
            }
            if ($request->filled('longitude')) {
                $user->longitude = $request->longitude;
            }
            if ($request->filled('userImage')) {
                $user->image = ImageKitHelper::uploadFile(
                    $request->userImage,
                    'customer_profile_' . $user->id . '_' . time()
                );
            }
            break;

        case 'rider':
            if ($request->filled('storeAddress')) {
                $user->address = $request->storeAddress;
            }
            if ($request->filled('vehicle')) {
                $user->vehicle = $request->vehicle;
            }
            if ($request->filled('licenseNumber')) {
                $user->license_number = $request->licenseNumber;
            }
            if ($request->filled('idDocument')) {
                $user->id_document = ImageKitHelper::uploadFile(
                    $request->idDocument,
                    'rider_id_' . $user->id . '_' . time()
                );
            }
            if ($request->filled('userImage')) {
                $user->image = ImageKitHelper::uploadFile(
                    $request->userImage,
                    'rider_profile_' . $user->id . '_' . time()
                );
            }
            break;
    }

    $user->save();

    return response()->json([
        'message' => 'Profile updated successfully',
        'user'    => $user->fresh(),
    ]);
}

    /**
     * Toggle user active/blocked status (admin only)
     */
    public function toggleUserStatus($id)
    {
        try {
            $user = User::findOrFail($id);
            $user->is_active = !$user->is_active;
            $user->save();

            $status = $user->is_active ? 'active' : 'blocked';
            return response()->json([
                'message' => 'User status updated successfully',
                'data' => [
                    'id' => (string) $user->id,
                    'name' => $user->fullname ?? $user->email,
                    'email' => $user->email,
                    'role' => ucfirst($user->user_type ?? 'customer'),
                    'status' => $status,
                    'created_at' => $user->created_at->format('Y-m-d'),
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => true, 'message' => 'User not found'], 404);
        }
    }
}
