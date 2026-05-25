<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'geoapify' => [
    'key' => env('GEOAPIFY_KEY'),
],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'inventory' => [
        'url' => env('INVENTORY_SERVICE_URL', 'http://127.0.0.1:8001/api/v1'),
        'api_token' => env('INVENTORY_API_TOKEN', 'zX9kPqW3mT7rY2vN8jL4hB6fD5cG1aE1'),
    ],
    'ipgeolocation' => [
        'key' => env('IPGEOLOCATION_KEY'),
    ],

    'imagekit' => [
        'private' => env('IMAGEKIT_PRIVATE_KEY'),
        'public'  => env('IMAGEKIT_PUBLIC_KEY'),
        'folder'      => env('IMAGEKIT_FOLDER', '/blogs'),
    ],

    'sendcloud' => [
        'public' => env('SENDCLOUD_API_PUBLIC'),
        'secret' => env('SENDCLOUD_API_SECRET'),
        'url' => env('SENDCLOUD_API_URL'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'key' => env('STRIPE_PUBLISHABLE_KEY'),
    ],

    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
        'rider_assignment_radius_km' => env('RIDER_ASSIGNMENT_RADIUS_KM', 12),
    ],

];
