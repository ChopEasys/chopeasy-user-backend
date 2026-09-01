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

        $title = $payload['title'] ?? ($payload['heading'] ?? 'ChopEasy');
        $body = $payload['body'] ?? ($payload['message'] ?? '');

        // Everything that isn't title/body is passed through as tap data so the
        // app can deep-link (order_id, type, etc.).
        $data = $payload['data'] ?? array_diff_key($payload, array_flip(['title', 'body', 'heading', 'message']));

        // Expo accepts up to 100 messages per request.
        $messages = [];
        foreach ($tokens as $token) {
            $messages[] = [
                'to' => $token,
                'sound' => 'default',
                'title' => (string) $title,
                'body' => (string) $body,
                'data' => $data,
                'priority' => 'high',
                'channelId' => 'default',
            ];
        }

        $invalidTokens = [];
        $anySuccess = false;

        try {
            foreach (array_chunk($messages, 100) as $chunk) {
                $response = Http::withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])->timeout(15)->post(self::EXPO_ENDPOINT, $chunk);

                if (!$response->successful()) {
                    Log::warning('Expo push request failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    continue;
                }

                $tickets = $response->json('data') ?? [];

                foreach ($tickets as $index => $ticket) {
                    $status = $ticket['status'] ?? null;

                    if ($status === 'ok') {
                        $anySuccess = true;
                        continue;
                    }

                    // status === 'error'
                    $errorType = $ticket['details']['error'] ?? null;
                    if ($errorType === 'DeviceNotRegistered') {
                        // Token is dead — mark for removal.
                        $deadToken = $chunk[$index]['to'] ?? null;
                        if ($deadToken) {
                            $invalidTokens[] = $deadToken;
                        }
                    }

                    Log::warning('Expo push ticket error', [
                        'error' => $errorType,
                        'message' => $ticket['message'] ?? null,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Expo push delivery threw', [
                'exception' => $e->getMessage(),
            ]);

            return ['success' => false, 'invalid_tokens' => $invalidTokens];
        }

        return ['success' => $anySuccess, 'invalid_tokens' => $invalidTokens];
    }
}
