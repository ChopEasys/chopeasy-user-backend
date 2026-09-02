<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends notifications to native mobile devices via the Expo Push API.
 *
 * Expo tokens (ExponentPushToken[...]) are stored in the `endpoint` column of
 * push_subscriptions. This service delivers to them over HTTPS and reports
 * back which tokens are invalid so the caller can prune them.
 *
 * Docs: https://docs.expo.dev/push-notifications/sending-notifications/
 */
class ExpoPushService
{
    private const EXPO_ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    /**
     * Default title when the payload title is missing or empty.
     */
    private const DEFAULT_TITLE = 'ChopEasy';

    /**
     * Default body when the payload body is missing or empty.
     */
    private const DEFAULT_BODY = 'You have a new notification';

    /**
     * Maximum number of delivery attempts per token when the failure is
     * transient (network timeout, connection failure, or a 5xx response from
     * the Expo Push service). A token is only recorded as failed after this
     * many attempts have been exhausted (Requirement 6.5).
     */
    private const MAX_ATTEMPTS_PER_TOKEN = 3;

    /**
     * Send a notification to a batch of Expo push tokens.
     *
     * @param  array<int, string>  $tokens   Expo push tokens
     * @param  array               $payload  Notification payload with keys:
     *                                        title, body, and arbitrary data.
     * @return array{success: bool, invalid_tokens: array<int, string>}
     */
    public function send(array $tokens, array $payload): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));

        if (empty($tokens)) {
            return ['success' => false, 'invalid_tokens' => []];
        }

        $title = $payload['title'] ?? ($payload['heading'] ?? self::DEFAULT_TITLE);
        if (trim((string) $title) === '') {
            $title = self::DEFAULT_TITLE;
        }

        $body = $payload['body'] ?? ($payload['message'] ?? '');
        if (trim((string) $body) === '') {
            $body = self::DEFAULT_BODY;
        }

        // Everything that isn't title/body is passed through as tap data so the
        // app can deep-link (order_id, type, etc.).
        $data = (isset($payload['data']) && is_array($payload['data']))
            ? $payload['data']
            : array_diff_key($payload, array_flip(['title', 'body', 'heading', 'message']));

        // Guarantee the tap data always carries the notification type so the
        // mobile deep-link router can resolve a target for every notification.
        if (!isset($data['type']) && isset($payload['type'])) {
            $data['type'] = $payload['type'];
        }

        $invalidTokens = [];
        $anySuccess = false;

        // Deliver one token at a time so a single token's transient failure is
        // retried in isolation (up to MAX_ATTEMPTS_PER_TOKEN) and never aborts
        // delivery to the user's remaining subscriptions (Requirements 6.4, 6.5).
        foreach ($tokens as $token) {
            $message = [
                'to' => $token,
                'sound' => 'default',
                'title' => (string) $title,
                'body' => (string) $body,
                'data' => $data,
                'priority' => 'high',
                'channelId' => 'default',
            ];

            $outcome = $this->sendSingleWithRetry($token, $message);

            if ($outcome['success']) {
                $anySuccess = true;
            }

            if ($outcome['invalid']) {
                $invalidTokens[] = $token;
            }
        }

        return ['success' => $anySuccess, 'invalid_tokens' => array_values(array_unique($invalidTokens))];
    }

    /**
     * Attempt delivery to a single token, retrying transient failures (network
     * timeout, connection failure, or 5xx) up to MAX_ATTEMPTS_PER_TOKEN times.
     * Short-circuits on the first success (Requirement 6.5).
     *
     * A `DeviceNotRegistered` ticket is a permanent failure that is not retried
     * and flags the token for pruning (Requirement 6.3).
     *
     * @param  string  $token
     * @param  array   $message  The single Expo message to POST.
     * @return array{success: bool, invalid: bool}
     */
    private function sendSingleWithRetry(string $token, array $message): array
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS_PER_TOKEN; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])->timeout(15)->post(self::EXPO_ENDPOINT, [$message]);
            } catch (\Throwable $e) {
                // Network timeout / connection failure — transient, retry.
                Log::warning('Expo push delivery threw (transient)', [
                    'endpoint' => $token,
                    'attempt' => $attempt,
                    'exception' => $e->getMessage(),
                ]);
                continue;
            }

            // 5xx from the Expo service is transient — retry.
            if ($response->serverError()) {
                Log::warning('Expo push request failed (server error)', [
                    'endpoint' => $token,
                    'attempt' => $attempt,
                    'status' => $response->status(),
                ]);
                continue;
            }

            // Any other non-successful (e.g. 4xx) is not retryable here.
            if (!$response->successful()) {
                Log::warning('Expo push request failed', [
                    'endpoint' => $token,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['success' => false, 'invalid' => false];
            }

            $ticket = ($response->json('data') ?? [])[0] ?? null;
            $status = $ticket['status'] ?? null;

            if ($status === 'ok') {
                return ['success' => true, 'invalid' => false];
            }

            // status === 'error' — determine if the token is permanently invalid.
            $errorType = $ticket['details']['error'] ?? null;

            Log::warning('Expo push ticket error', [
                'endpoint' => $token,
                'error' => $errorType,
                'message' => $ticket['message'] ?? null,
            ]);

            if ($errorType === 'DeviceNotRegistered') {
                // Permanently invalid — prune and do not retry.
                return ['success' => false, 'invalid' => true];
            }

            // Ticket-level error that is not DeviceNotRegistered is treated as a
            // non-retryable delivery failure for this token.
            return ['success' => false, 'invalid' => false];
        }

        // All bounded attempts exhausted on transient failures — record failed.
        Log::warning('Expo push delivery failed after retries', [
            'endpoint' => $token,
            'attempts' => self::MAX_ATTEMPTS_PER_TOKEN,
        ]);

        return ['success' => false, 'invalid' => false];
    }
}
