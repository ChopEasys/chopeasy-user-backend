<?php

namespace Tests\Unit;

use App\Constants\PushNotificationType;
use App\Jobs\SendPushNotificationJob;
use App\Services\ExpoPushService;
use App\Services\PushDispatchService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reinforcement tests for outbound push payload shaping (Requirement 6.2 /
 * design Property 15): title clamped to 1–100 chars, body clamped to 1–240
 * chars with defaults, and the data object always carrying the notification
 * type plus deep-link identifiers.
 */
class PushPayloadShapingTest extends TestCase
{
    private PushDispatchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PushDispatchService();
        Bus::fake();
    }

    /**
     * Capture the payload that PushDispatchService queues for a given input.
     */
    private function shapedPayload(string $type, array $payload): array
    {
        $this->service->dispatch(1, $type, $payload);

        $captured = null;
        Bus::assertDispatched(SendPushNotificationJob::class, function (SendPushNotificationJob $job) use (&$captured) {
            $captured = $job->payload;
            return true;
        });

        return $captured;
    }

    #[Test]
    public function it_replaces_missing_title_and_body_with_defaults(): void
    {
        $shaped = $this->shapedPayload(PushNotificationType::ORDER_CONFIRMED, [
            'url' => '/orders/5',
        ]);

        $this->assertSame('ChopEasy', $shaped['title']);
        $this->assertSame('You have a new notification', $shaped['body']);
    }

    #[Test]
    public function it_replaces_empty_title_and_body_with_defaults(): void
    {
        $shaped = $this->shapedPayload(PushNotificationType::ORDER_CONFIRMED, [
            'title' => '   ',
            'body' => '',
        ]);

        $this->assertSame('ChopEasy', $shaped['title']);
        $this->assertSame('You have a new notification', $shaped['body']);
    }

    #[Test]
    public function it_clamps_title_to_100_characters(): void
    {
        $longTitle = str_repeat('A', 250);

        $shaped = $this->shapedPayload(PushNotificationType::ORDER_CONFIRMED, [
            'title' => $longTitle,
            'body' => 'ok',
        ]);

        $this->assertSame(100, mb_strlen($shaped['title']));
    }

    #[Test]
    public function it_clamps_body_to_240_characters(): void
    {
        $longBody = str_repeat('B', 500);

        $shaped = $this->shapedPayload(PushNotificationType::ORDER_CONFIRMED, [
            'title' => 'ok',
            'body' => $longBody,
        ]);

        $this->assertSame(240, mb_strlen($shaped['body']));
    }

    #[Test]
    public function it_preserves_in_bounds_title_and_body(): void
    {
        $shaped = $this->shapedPayload(PushNotificationType::ORDER_CONFIRMED, [
            'title' => 'Order Confirmed',
            'body' => 'Your order is confirmed.',
        ]);

        $this->assertSame('Order Confirmed', $shaped['title']);
        $this->assertSame('Your order is confirmed.', $shaped['body']);
    }

    #[Test]
    public function data_object_always_carries_the_notification_type(): void
    {
        $shaped = $this->shapedPayload(PushNotificationType::DELIVERY_AVAILABLE, [
            'title' => 'New Delivery',
            'body' => 'A delivery is available.',
        ]);

        $this->assertArrayHasKey('data', $shaped);
        $this->assertSame(PushNotificationType::DELIVERY_AVAILABLE, $shaped['data']['type']);
    }

    #[Test]
    public function it_promotes_top_level_order_id_into_data(): void
    {
        // Order-status payloads carry no data object and only a url; the
        // deep-link id must still end up in data.
        $shaped = $this->shapedPayload(PushNotificationType::ORDER_CONFIRMED, [
            'title' => 'Order Confirmed',
            'body' => 'Your order is confirmed.',
            'url' => '/orders/42',
            'order_id' => 42,
        ]);

        $this->assertSame(42, $shaped['data']['order_id']);
        $this->assertSame(PushNotificationType::ORDER_CONFIRMED, $shaped['data']['type']);
    }

    #[Test]
    public function it_promotes_top_level_plan_id_into_data(): void
    {
        $shaped = $this->shapedPayload(PushNotificationType::DEDUCTION_REMINDER, [
            'title' => 'Savings reminder',
            'body' => 'Your deduction is due.',
            'plan_id' => 77,
        ]);

        $this->assertSame(77, $shaped['data']['plan_id']);
        $this->assertSame(PushNotificationType::DEDUCTION_REMINDER, $shaped['data']['type']);
    }

    #[Test]
    public function it_preserves_existing_nested_data_ids_and_adds_type(): void
    {
        $shaped = $this->shapedPayload(PushNotificationType::VENDOR_NEW_ORDER, [
            'title' => 'New Order',
            'body' => 'You have a new order.',
            'url' => '/vendor/orders/9',
            'data' => [
                'order_id' => 9,
                'order_number' => 'CE-9',
            ],
        ]);

        $this->assertSame(9, $shaped['data']['order_id']);
        $this->assertSame('CE-9', $shaped['data']['order_number']);
        $this->assertSame(PushNotificationType::VENDOR_NEW_ORDER, $shaped['data']['type']);
    }

    #[Test]
    public function expo_service_defaults_missing_title_and_body(): void
    {
        Http::fake([
            'exp.host/*' => Http::response([
                'data' => [['status' => 'ok']],
            ], 200),
        ]);

        app(ExpoPushService::class)->send(['ExponentPushToken[abc]'], [
            'type' => PushNotificationType::ORDER_CONFIRMED,
        ]);

        Http::assertSent(function ($request) {
            $message = $request->data()[0];

            $this->assertSame('ChopEasy', $message['title']);
            $this->assertSame('You have a new notification', $message['body']);
            $this->assertSame(PushNotificationType::ORDER_CONFIRMED, $message['data']['type']);

            return true;
        });
    }

    #[Test]
    public function expo_service_uses_provided_title_and_body(): void
    {
        Http::fake([
            'exp.host/*' => Http::response([
                'data' => [['status' => 'ok']],
            ], 200),
        ]);

        app(ExpoPushService::class)->send(['ExponentPushToken[abc]'], [
            'title' => 'Order Confirmed',
            'body' => 'Your order is confirmed.',
            'type' => PushNotificationType::ORDER_CONFIRMED,
            'data' => ['order_id' => 5, 'type' => PushNotificationType::ORDER_CONFIRMED],
        ]);

        Http::assertSent(function ($request) {
            $message = $request->data()[0];

            $this->assertSame('Order Confirmed', $message['title']);
            $this->assertSame('Your order is confirmed.', $message['body']);
            $this->assertSame(5, $message['data']['order_id']);

            return true;
        });
    }
}
