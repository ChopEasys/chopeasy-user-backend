<?php

/**
 * Web Push Notifications Configuration
 *
 * VAPID keys must be Base64 URL-safe encoded ECDH P-256 key strings.
 * - Public key: 65 bytes uncompressed = 88 characters base64url
 * - Private key: 32 bytes = 43 characters base64url
 *
 * Generate keys using: php artisan webpush:generate-keys
 */

return [

    /*
    |--------------------------------------------------------------------------
    | VAPID Public Key
    |--------------------------------------------------------------------------
    |
    | The public key used to authenticate push notifications with browser
    | push services. Must be a Base64 URL-safe encoded uncompressed P-256 point.
    |
    */
    'vapid_public_key' => env('VAPID_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | VAPID Private Key
    |--------------------------------------------------------------------------
    |
    | The private key used to sign push notification payloads.
    | Must be a Base64 URL-safe encoded P-256 scalar.
    |
    */
    'vapid_private_key' => env('VAPID_PRIVATE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | VAPID Subject
    |--------------------------------------------------------------------------
    |
    | The subject (contact) for VAPID identification. Typically a mailto: URL
    | or your application URL.
    |
    */
    'vapid_subject' => env('APP_URL', 'https://api.chopeasy.ng'),

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    |
    | Expected key lengths for VAPID key validation.
    | Public key: 65 bytes uncompressed P-256 point = 88 chars base64url
    | Private key: 32 bytes P-256 scalar = 43 chars base64url
    |
    */
    'public_key_length' => 88,
    'private_key_length' => 43,

];
