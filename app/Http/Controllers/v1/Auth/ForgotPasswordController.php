<?php

namespace App\Http\Controllers\v1\Auth;

use App\Http\Controllers\Controller;
use App\Services\User\UserService;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResendOtpRequest;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use App\Responser\JsonResponser;
use Exception;
use Illuminate\Support\Facades\Log;

class ForgotPasswordController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function sendResetLink(ForgotPasswordRequest $request)
    {
        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return JsonResponser::send(true, 'No account found with that email.', null, 404);
            }

            // Generate 4-digit OTP
            $otp = rand(1000, 9999);

            // Update user with OTP and expiry
            $user->email_otp = $otp;
            $user->otp_expires_at = now()->addMinutes(10);
            $user->save();

            // Send OTP notification
            try {
                $user->notify(new EmailOtpNotification($user, $otp));
            } catch (\Throwable $e) {
                Log::info($e);
                return JsonResponser::send(true, 'Failed to send OTP email.', null, 500);
            }

            return JsonResponser::send(false, 'OTP sent to your email.', null, 200);
        } catch (\Exception $e) {
            return JsonResponser::send(true, 'Something went wrong.', null, 500);
        }
    }

    public function resendOtp(ResendOtpRequest $request)
    {
        try {
            $response = $this->userService->resendOtp($request->email);

            return JsonResponser::send(
                $response['error'] ?? false,
                $response['message'] ?? 'OTP resent successfully.',
                null,
                $response['status'] ?? 200
            );
        } catch (Exception $e) {
            return JsonResponser::send(
                true,
                'An error occurred while resending the OTP.',
                null,
                500
            );
        }
    }
}
