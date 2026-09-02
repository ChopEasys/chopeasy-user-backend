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
     * Maximum notification title length (characters).
     */
    private const TITLE_MAX_LENGTH = 100;

    /**
     * Maximum notification body length (characters).
     */
    private const BODY_MAX_LENGTH = 240;

    /**
     * Default title when the payload title is missing or empty.
     */
    private const DEFAULT_TITLE = 'ChopEasy';

    /**
     * Default body when the payload body is missing or empty.
     */
    private const DEFAULT_BODY = 'You have a new notification';

    /**
     * Deep-link identifier fields that must always be carried in the data object
     * so the mobile app can route notification taps (see Requirement 5).
     */
    private const DEEP_LINK_ID_FIELDS = ['order_id', 'plan_id'];

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
        // 0. Shape the payload so title/body are within bounds and the data
        //    object always carries the notification type + deep-link ids.
        $payload = $this->shapePayload($type, $payload);

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
     * Normalise a notification payload so that:
     *  - title is 1–100 chars (empty/missing replaced with the default), and
     *  - body is 1–240 chars (empty/missing replaced with the default), and
     *  - the data object always carries the notification `type` plus every
     *    available deep-link identifier (order_id, plan_id) required by the
     *    mobile deep-link router.
     *
     * @param string $type     Notification type constant from PushNotificationType
     * @param array  $payload  Raw notification payload from the caller
     * @return array           The reshaped payload
     */
    private function shapePayload(string $type, array $payload): array
    {
        $payload['title'] = $this->clampText(
            $payload['title'] ?? ($payload['heading'] ?? null),
            self::TITLE_MAX_LENGTH,
            self::DEFAULT_TITLE
        );

        $payload['body'] = $this->clampText(
            $payload['body'] ?? ($payload['message'] ?? null),
            self::BODY_MAX_LENGTH,
            self::DEFAULT_BODY
        );

        // Existing data object (if any) is preserved and extended.
        $data = isset($payload['data']) && is_array($payload['data'])
            ? $payload['data']
            : [];

        // Always carry the notification type for routing.
        $data['type'] = $type;

        // Promote known deep-link identifiers from the top-level payload into
        // the data object when they are present and valid, without overwriting
        // an id already supplied inside data.
        foreach (self::DEEP_LINK_ID_FIELDS as $field) {
            if (!array_key_exists($field, $data) && $this->isValidId($payload[$field] ?? null)) {
                $data[$field] = $payload[$field];
            }
        }

        $payload['data'] = $data;

        return $payload;
    }

    /**
     * Clamp a text value to at most $max characters, falling back to $default
     * when the value is missing or empty after trimming.
     */
    private function clampText($value, int $max, string $default): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        if ($text === '') {
            $text = $default;
        }

        // mb_substr keeps multibyte characters (e.g. emoji) intact.
        return mb_substr($text, 0, $max);
    }

    /**
     * Whether a value is a usable deep-link identifier: a non-empty string or a
     * finite number (not null/empty/NaN).
     */
    private function isValidId($value): bool
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value);
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return false;
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
