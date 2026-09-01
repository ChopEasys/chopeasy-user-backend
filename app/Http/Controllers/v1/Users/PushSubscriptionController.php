<?php

namespace App\Http\Controllers\v1\Users;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PushSubscriptionController extends Controller
{
    /**
     * Detect an Expo push token, which is delivered by the native mobile app
     * as the subscription endpoint (e.g. "ExponentPushToken[xxxx]").
     */
    private function isExpoToken(string $endpoint): bool
    {
        return str_starts_with($endpoint, 'ExponentPushToken[')
            || str_starts_with($endpoint, 'ExpoPushToken[');
    }

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

        if (empty($publicKey) || empty($privateKey)) {
            Log::error('Web Push: VAPID keys are missing.', [
                'public_key_set' => !empty($publicKey),
                'private_key_set' => !empty($privateKey),
            ]);

            return response()->json([
                'error' => 'Push notification service is not configured',
            ], 500);
        }

        $base64UrlPattern = '/^[A-Za-z0-9\-_=]+$/';

        if (!preg_match($base64UrlPattern, $publicKey) || !preg_match($base64UrlPattern, $privateKey)) {
            Log::error('Web Push: VAPID key contains invalid characters.');

            return response()->json([
                'error' => 'Push notification service is not configured',
            ], 500);
        }

        if (abs(strlen($publicKey) - $expectedPublicLength) > 2 || abs(strlen($privateKey) - $expectedPrivateLength) > 2) {
            Log::error('Web Push: VAPID key has incorrect length.', [
                'public' => strlen($publicKey),
                'private' => strlen($privateKey),
            ]);

            return response()->json([
                'error' => 'Push notification service is not configured',
            ], 500);
        }

        return response()->json([
            'public_key' => $publicKey,
        ], 200);
    }

    /**
     * Store or update a push subscription for the authenticated user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function subscribe(Request $request)
    {
        $endpoint = (string) $request->input('endpoint');
        $isExpo = $this->isExpoToken($endpoint);

        // Web push (browser) sends real p256dh/auth keys. Native mobile sends an
        // Expo push token as the endpoint and has no keys, so those are optional
        // for Expo tokens and stored as placeholders.
        $request->validate([
            'endpoint' => 'required|string|max:500',
            'keys.p256dh' => [$isExpo ? 'nullable' : 'required', 'string', 'max:255'],
            'keys.auth' => [$isExpo ? 'nullable' : 'required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $subscription = PushSubscription::updateOrCreate(
            [
                'user_id' => $user->id,
                'endpoint' => $endpoint,
            ],
            [
                'p256dh_key' => $isExpo ? 'expo' : $request->input('keys.p256dh'),
                'auth_secret' => $isExpo ? 'expo' : $request->input('keys.auth'),
            ]
        );

        // Enforce max 10 subscriptions per user — remove oldest if exceeded
        $count = PushSubscription::where('user_id', $user->id)->count();
        if ($count > 10) {
            PushSubscription::where('user_id', $user->id)
                ->orderBy('created_at', 'asc')
                ->limit($count - 10)
                ->delete();
        }

        $statusCode = $subscription->wasRecentlyCreated ? 201 : 200;

        return response()->json([
            'message' => 'Subscription registered successfully',
        ], $statusCode);
    }

    /**
     * Remove a push subscription for the authenticated user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function unsubscribe(Request $request)
    {
        $request->validate([
            'endpoint' => 'required|string',
        ]);

        $user = $request->user();

        PushSubscription::where('user_id', $user->id)
            ->where('endpoint', $request->input('endpoint'))
            ->delete();

        return response()->json([
            'message' => 'Subscription removed',
        ], 200);
    }
}
