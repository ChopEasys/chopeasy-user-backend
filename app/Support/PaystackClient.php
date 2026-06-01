<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PaystackClient
{
    public static function secretKey(): ?string
    {
        $key = config('services.paystack.secret_key', env('PAYSTACK_SECRET_KEY'));

        if (!is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key !== '' ? $key : null;
    }

    public static function baseUrl(): string
    {
        return rtrim(
            (string) config('services.paystack.payment_url', env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co')),
            '/'
        );
    }

    public static function configured(): bool
    {
        return self::secretKey() !== null;
    }

    public static function http()
    {
        return Http::withToken(self::secretKey() ?? '');
    }

    public static function get(string $path, array $query = []): Response
    {
        return self::http()->get(self::baseUrl() . '/' . ltrim($path, '/'), $query);
    }
}
