<?php

namespace App\Services;

use App\Jobs\SendPushNotificationJob;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PushDispatchService
{
    /**
     * Maximum payload size in bytes (4KB).
     */
    private const MAX_PAYLOAD_SIZE = 4096;

    /**
     * Maximum notifications per user per hour.
     */
    private const RATE_LIMIT_MAX = 30;

    /**
     * Rate limit TTL in seconds (1 hour).
     */
    private const RATE_LIMIT_TTL = 3600;

    /**
     * Dispatch a push notification to all active subscriptions for a user.
     *
     * @param int    $userId   Target user ID
     * @param string $type     Notification type constant from PushNotificationType
     * @param array  $payload  Notification payload (must be <= 4KB serialized)
     * @return bool  Whether dispatch was queued (false if rate-limited or invalid)
     */
    public function dispatch(int $userId, string $type, array $payload): bool
    {
        // 1. Validate payload size <= 4KB
        $serialized = json_encode($payload);

        if ($serialized === false || strlen($serialized) > self::MAX_PAYLOAD_SIZE) {
            Log::warning('Push notification payload exceeds 4KB limit', [
                'user_id' => $userId,
                'type' => $type,
                'payload_size' => $serialized === false ? 'encoding_failed' : strlen($serialized),
            ]);

            return false;
        }

        // 2. Check rate limit (30/user/hour) using Cache
        $hourBucket = date('Y-m-d-H');
        $rateLimitKey = "push_rate:{$userId}:{$hourBucket}";
        $currentCount = (int) Cache::get($rateLimitKey, 0);

        if ($currentCount >= self::RATE_LIMIT_MAX) {
            Log::info('Push notification rate limit exceeded', [
                'user_id' => $userId,
                'type' => $type,
                'current_count' => $currentCount,
                'hour_bucket' => $hourBucket,
            ]);

            return false;
        }

        // 3. Increment rate counter
        Cache::put($rateLimitKey, $currentCount + 1, self::RATE_LIMIT_TTL);

        // 4. Dispatch SendPushNotificationJob to queue
        SendPushNotificationJob::dispatch($userId, $type, $payload);

        return true;
    }

    /**
     * Remove expired subscriptions for a user.
     *
     * @param int $userId
     * @return void
     */
    public function cleanupSubscriptions(int $userId): void
    {
        PushSubscription::where('user_id', $userId)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();
    }
}
