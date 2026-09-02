<?php

namespace Tests\Feature;

use App\Constants\PushNotificationType;
use App\Jobs\SendPushNotificationJob;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verification tests for Task 11.8 — Requirement 6.6.
 *
 * When a user has no subscriptions, or every delivery fails, exactly ONE
 * unread in-app (database) notification is persisted via
 * SendPushNotificationJob::storeAsDatabaseNotification() and is retrievable
 * from the notifiable's notifications relation.
 *
 * (The cross-channel rate limit — Requirement 6.7 — is verified without a
 * database in Tests\Unit\PushRateLimitTest.)
 */
class PushFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = [
        'title' => 'Order Confirmed',
        'body' => 'Your order is confirmed.',
        'order_id' => 5,
    ];

    private function makeUser(): User
    {
        return User::create([
            'fullname' => 'Push Recipient',
            'email' => 'push-recipient@example.com',
            'phoneno' => '08099999999',
            'user_type' => 'customer',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);
    }

    // ----------------------------------------------------------------------
    // Requirement 6.6 — in-app fallback fires exactly once
    // ----------------------------------------------------------------------

    #[Test]
    public function it_stores_exactly_one_unread_fallback_when_user_has_no_subscriptions(): void
    {
        $user = $this->makeUser();

        // No push_subscriptions rows exist for this user.
        (new SendPushNotificationJob($user->id, PushNotificationType::ORDER_CONFIRMED, self::PAYLOAD))
            ->handle();

        $user->refresh();
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(1, $user->unreadNotifications()->count());

        $notification = $user->notifications()->first();
        $this->assertNull($notification->read_at);
        $this->assertTrue($notification->data['push_fallback']);
        $this->assertSame(PushNotificationType::ORDER_CONFIRMED, $notification->data['type']);
    }

    #[Test]
    public function it_stores_exactly_one_unread_fallback_when_all_expo_deliveries_fail(): void
    {
        $user = $this->makeUser();

        PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => 'ExponentPushToken[fails]',
            'p256dh_key' => 'expo',
            'auth_secret' => 'expo',
        ]);

        // Expo returns a permanent 5xx for every attempt -> delivery fails.
        Http::fake([
            'exp.host/*' => Http::response('', 503),
        ]);

        (new SendPushNotificationJob($user->id, PushNotificationType::ORDER_CONFIRMED, self::PAYLOAD))
            ->handle();

        $user->refresh();
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(1, $user->unreadNotifications()->count());
    }

    #[Test]
    public function it_does_not_store_a_fallback_when_at_least_one_delivery_succeeds(): void
    {
        $user = $this->makeUser();

        PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => 'ExponentPushToken[ok]',
            'p256dh_key' => 'expo',
            'auth_secret' => 'expo',
        ]);

        Http::fake([
            'exp.host/*' => Http::response(['data' => [['status' => 'ok']]], 200),
        ]);

        (new SendPushNotificationJob($user->id, PushNotificationType::ORDER_CONFIRMED, self::PAYLOAD))
            ->handle();

        $user->refresh();
        $this->assertSame(0, $user->notifications()->count());
    }

    #[Test]
    public function the_failed_handler_also_stores_a_single_unread_fallback(): void
    {
        $user = $this->makeUser();

        (new SendPushNotificationJob($user->id, PushNotificationType::ORDER_CONFIRMED, self::PAYLOAD))
            ->failed(new \RuntimeException('permanent failure'));

        $user->refresh();
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(1, $user->unreadNotifications()->count());
    }
}
