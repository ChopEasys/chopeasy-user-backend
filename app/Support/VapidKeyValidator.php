<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class VapidKeyValidator
{
    /**
     * Validate that both VAPID keys are present and correctly formatted.
     *
     * @return array{valid: bool, error: string|null}
     */
    public static function validate(): array
    {
        $publicKey = config('webpush.vapid_public_key');
        $privateKey = config('webpush.vapid_private_key');

        // Check keys are present
        if (empty($publicKey) || empty($privateKey)) {
            $missing = [];
            if (empty($publicKey)) {
                $missing[] = 'VAPID_PUBLIC_KEY';
            }
            if (empty($privateKey)) {
                $missing[] = 'VAPID_PRIVATE_KEY';
            }

            $error = 'Missing VAPID environment variable(s): ' . implode(', ', $missing);
            Log::error('[WebPush] ' . $error);

            return ['valid' => false, 'error' => $error];
        }

        // Validate public key format and length
        if (!static::isValidBase64Url($publicKey)) {
            $error = 'VAPID_PUBLIC_KEY is not valid Base64 URL-safe encoded';
            Log::error('[WebPush] ' . $error);

            return ['valid' => false, 'error' => $error];
        }

        $expectedPublicKeyLength = config('webpush.public_key_length', 88);
        if (strlen($publicKey) !== $expectedPublicKeyLength) {
            $error = sprintf(
                'VAPID_PUBLIC_KEY has invalid length: expected %d characters (65-byte P-256 uncompressed point), got %d',
                $expectedPublicKeyLength,
                strlen($publicKey)
            );
            Log::error('[WebPush] ' . $error);

            return ['valid' => false, 'error' => $error];
        }

        // Validate private key format and length
        if (!static::isValidBase64Url($privateKey)) {
            $error = 'VAPID_PRIVATE_KEY is not valid Base64 URL-safe encoded';
            Log::error('[WebPush] ' . $error);

            return ['valid' => false, 'error' => $error];
        }

        $expectedPrivateKeyLength = config('webpush.private_key_length', 43);
        if (strlen($privateKey) !== $expectedPrivateKeyLength) {
            $error = sprintf(
                'VAPID_PRIVATE_KEY has invalid length: expected %d characters (32-byte P-256 scalar), got %d',
                $expectedPrivateKeyLength,
                strlen($privateKey)
            );
            Log::error('[WebPush] ' . $error);

            return ['valid' => false, 'error' => $error];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Check if a string is valid Base64 URL-safe encoded.
     *
     * Base64 URL-safe uses characters: A-Z, a-z, 0-9, -, _
     * Padding (=) is optional.
     */
    public static function isValidBase64Url(string $value): bool
    {
        // Base64 URL-safe alphabet: A-Za-z0-9-_ with optional = padding
        return (bool) preg_match('/^[A-Za-z0-9\-_]+=*$/', $value);
    }
}
