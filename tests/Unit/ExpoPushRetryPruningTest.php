<?php

namespace Tests\Unit;

use App\Constants\PushNotificationType;
use App\Services\ExpoPushService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reinforcement tests for ExpoPushService invalid-token pruning and scoped
 * per-token transient-failure retry.
 *
 * - Requirement 6.3: DeviceNotRegistered tokens are collected for pruning and
 *   are not retried.
 * - Requirement 6.4: a single token's failure does not abort delivery to the
 *   user's remaining subscriptions.
 * - Requirement 6.5: a transient failure (timeout/connection/5xx) for one token
 *   is retried up to 3 times, short-circuiting on the first success, before the
 *   token is recorded as failed.
 */
class ExpoPushRetryPruningTest extends TestCase
{
    private ExpoPushService $service;

    private const PAYLOAD = [
        'title' => 'Order Confirmed',
        'body' => 'Your order is confirmed.',
        'type' => PushNotificationType::ORDER_CONFIRMED,
        'data' => ['order_id' => 5, 'type' => PushNotificationType::ORDER_CONFIRMED],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExpoPushService();
    }

    private function okTicket(): array
    {
        return ['data' => [['status' => 'ok']]];
    }

    private function deviceNotRegisteredTicket(): array
    {
        return [
            'data' => [[
                'status' => 'error',
                'message' => 'Device not registered',
                'details' => ['error' => 'DeviceNotRegistered'],
            ]],
        ];
    }

    #[Test]
    public function it_flags_device_not_registered_tokens_for_pruning_without_retry(): void
    {
        Http::fake([
            'exp.host/*' => Http::response($this->deviceNotRegisteredTicket(), 200),
        ]);

        $result = $this->service->send(['ExponentPushToken[dead]'], self::PAYLOAD);

        $this->assertFalse($result['success']);
        $this->assertSame(['ExponentPushToken[dead]'], $result['invalid_tokens']);

        // A permanent DeviceNotRegistered error must NOT be retried: exactly one request.
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_retries_transient_5xx_up_to_three_times_then_records_failed(): void
    {
        Http::fake([
            'exp.host/*' => Http::sequence()
                ->push('', 503)
                ->push('', 503)
                ->push('', 503),
        ]);

        $result = $this->service->send(['ExponentPushToken[flaky]'], self::PAYLOAD);

        $this->assertFalse($result['success']);
        $this->assertSame([], $result['invalid_tokens']);

        // Exactly 3 attempts for the single token.
        Http::assertSentCount(3);
    }

    #[Test]
    public function it_short_circuits_on_first_success_after_transient_failures(): void
    {
        Http::fake([
            'exp.host/*' => Http::sequence()
                ->push('', 500)
                ->push($this->okTicket(), 200),
        ]);

        $result = $this->service->send(['ExponentPushToken[recovers]'], self::PAYLOAD);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['invalid_tokens']);

        // One failure + one success = 2 attempts, no third attempt.
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_retries_connection_exceptions_as_transient(): void
    {
        Http::fake([
            'exp.host/*' => Http::sequence()
                ->pushFailedConnection('Connection timed out')
                ->push($this->okTicket(), 200),
        ]);

        $result = $this->service->send(['ExponentPushToken[timeout]'], self::PAYLOAD);

        $this->assertTrue($result['success']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function a_single_token_failure_does_not_abort_delivery_to_other_tokens(): void
    {
        // First token permanently 5xx (fails after 3 attempts), second succeeds.
        Http::fake(function ($request) {
            $to = $request->data()[0]['to'] ?? null;

            if ($to === 'ExponentPushToken[bad]') {
                return Http::response('', 503);
            }

            return Http::response($this->okTicket(), 200);
        });

        $result = $this->service->send([
            'ExponentPushToken[bad]',
            'ExponentPushToken[good]',
        ], self::PAYLOAD);

        // Delivery to the good token still succeeded despite the bad one failing.
        $this->assertTrue($result['success']);
        $this->assertSame([], $result['invalid_tokens']);

        // 3 attempts for the bad token + 1 for the good token.
        Http::assertSentCount(4);
    }

    #[Test]
    public function it_prunes_one_token_and_continues_delivering_to_the_rest(): void
    {
        Http::fake(function ($request) {
            $to = $request->data()[0]['to'] ?? null;

            if ($to === 'ExponentPushToken[dead]') {
                return Http::response($this->deviceNotRegisteredTicket(), 200);
            }

            return Http::response($this->okTicket(), 200);
        });

        $result = $this->service->send([
            'ExponentPushToken[dead]',
            'ExponentPushToken[live]',
        ], self::PAYLOAD);

        $this->assertTrue($result['success']);
        $this->assertSame(['ExponentPushToken[dead]'], $result['invalid_tokens']);

        // dead token = 1 attempt (no retry), live token = 1 attempt.
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_does_not_retry_non_5xx_client_errors(): void
    {
        Http::fake([
            'exp.host/*' => Http::response('', 400),
        ]);

        $result = $this->service->send(['ExponentPushToken[bad-request]'], self::PAYLOAD);

        $this->assertFalse($result['success']);
        $this->assertSame([], $result['invalid_tokens']);

        // 4xx is not transient — exactly one attempt.
        Http::assertSentCount(1);
    }
}
