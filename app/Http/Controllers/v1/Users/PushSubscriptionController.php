<?php

namespace App\Http\Controllers\v1\Users;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;

class PushSubscriptionController extends Controller
{
    /**
     * Return the VAPID public key for client-side push subscription creation.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function vapidKey()
    {
        $publicKey = config('webpush.vapid_public_key');
        $privateKey = config('webpush.vapid_private_key');
        $expectedPublicLength = config('webpush.public_key_length');
        $expectedPrivateLength = config('webpush.private_key_length');

        // Check that both keys are present
        if (empty($publicKey) || empty($privateKey)) {
            Log::error('Web Push: VAPID keys are missing.', [
                'public_key_set' => !empty($publicKey),
                'private_key_set' => !empty($privateKey),
            ]);

            return response()->json([
                'error' => 'Push notification service is not configured',
            ], 500);
        }

        // Validate Base64 URL-safe encoding (A-Z, a-z, 0-9, -, _, =)
        $base64UrlPattern = '/^[A-Za-z0-9\-_=]+$/';

        if (!preg_match($base64UrlPattern, $publicKey)) {
            Log::error('Web Push: VAPID public key contains invalid characters (not Base64 URL-safe).', [
                'public_key_length' => strlen($publicKey),
            ]);

            return response()->json([
                'error' => 'Push notification service is not configured',
            ], 500);
        }

        if (!preg_match($base64UrlPattern, $privateKey)) {
            Log::error('Web Push: VAPID private key contains invalid characters (not Base64 URL-safe).', [
                'private_key_length' => strlen($privateKey),
            ]);

            return response()->json([
                'error' => 'Push notification service is not configured',
            ], 500);
        }

        // Validate key lengths
        if (strlen($publicKey) !== $expectedPublicLength) {
            Log::error('Web Push: VAPID public key has incorrect length.', [
                'expected' => $expectedPublicLength,
                'actual' => strlen($publicKey),
            ]);

            return response()->json([
                'error' => 'Push notification service is not configured',
            ], 500);
        }

        if (strlen($privateKey) !== $expectedPrivateLength) {
            Log::error('Web Push: VAPID private key has incorrect length.', [
                'expected' => $expectedPrivateLength,
                'actual' => strlen($privateKey),
            ]);

            return response()->json([
                'error' => 'Push notification service is not configured',
            ], 500);
        }

        return response()->json([
            'public_key' => $publicKey,
        ], 200);
    }
}
