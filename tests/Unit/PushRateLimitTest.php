<?php

namespace Tests\Unit;

use App\Constants\PushNotificationType;
use App\Jobs\SendPushNotificationJob;
use App\Services\PushDispatchService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verification tests for Task 11.8 — Requirement 6.7.
 *
 * PushDispatchService::dispatch() applies the existing per-user rate limit
 * (30/hour) uniformly. The limit is keyed only on (user id, hour bucket) and
 * is checked before the SendPushNotificationJob is queued, so it is enforced
 * identically no matter which channel (Expo native or web-push VAPID) the
 * user's subscriptions belong to.
 *
 * These tests need no database: dispatch() only touches Cache (rate counter)
 * and the queue (faked with Bus).
 */
class PushRateLimitTest extends TestCase
{
    private const PAYLOAD = [
        'title' => 'Order Confirmed',
        'body' => 'Your order is confirmed.',
        'order_id' => 5,
    ];

    private PushDispatchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Cache::flush();
        $this->service = new PushDispatchService();
    }

    #[Test]
    public function it_queues_at_most_30_dispatches_per_user_per_hour(): void
    {
        $userId = 4242;
        $queued = 0;
        $refused = 0;

        for ($i = 0; $i < 40; $i++) {
            if ($this->service->dispatch($userId, PushNotificationType::ORDER_CONFIRMED, self::PAYLOAD)) {
                $queued++;
            } else {
                $refused++;
            }
        }

        $this->assertSame(30, $queued, 'Exactly 30 notifications should be queued within the window.');
        $this->assertSame(10, $refused, 'Every attempt beyond the limit should be refused.');

        Bus::assertDispatchedTimes(SendPushNotificationJob::class, 30);
    }

    #[Test]
    public function the_rate_limit_counter_is_shared_across_notification_types_and_channels(): void
    {
        $userId = 7373;

        // Mix notification types that map to different downstream channels /
        // deep links; they all count against the same per-user window.
        $types = [
            PushNotificationType::ORDER_CONFIRMED,
            PushNotificationType::DELIVERY_AVAILABLE,
            PushNotificationType::DEDUCTION_REMINDER,
        ];

        $queued = 0;
        for ($i = 0; $i < 33; $i++) {
            $type = $types[$i % count($types)];
            if ($this->service->dispatch($userId, $type, self::PAYLOAD)) {
                $queued++;
            }
        }

        $this->assertSame(30, $queued);
        Bus::assertDispatchedTimes(SendPushNotificationJob::class, 30);
    }

    #[Test]
    public function the_rate_limit_is_isolated_per_user(): void
    {
        // Exhaust user A's window.
        for ($i = 0; $i < 30; $i++) {
            $this->service->dispatch(1, PushNotificationType::ORDER_CONFIRMED, self::PAYLOAD);
        }

        // User A is now refused, but user B still has a full window.
        $this->assertFalse($this->service->dispatch(1, PushNotificationType::ORDER_CONFIRMED, self::PAYLOAD));
        $this->assertTrue($this->service->dispatch(2, PushNotificationType::ORDER_CONFIRMED, self::PAYLOAD));
    }
}
