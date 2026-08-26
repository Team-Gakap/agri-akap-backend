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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS Gateway
    |--------------------------------------------------------------------------
    | AGRI-AKAP can dispatch bulk/transactional SMS through either IPROG or
    | Semaphore. Switch providers with SMS_PROVIDER (iprog|semaphore).
    */
    'sms' => [
        'provider' => env('SMS_PROVIDER', 'iprog'),

        'iprog' => [
            'token' => env('IPROG_API_TOKEN'),
            'base_url' => env('IPROG_BASE_URL', 'https://sms.iprogtech.com'),
            'sender' => env('IPROG_SENDER_NAME', 'MAO-ECHAGUE'),
        ],

        'semaphore' => [
            'key' => env('SEMAPHORE_API_KEY'),
            'sender' => env('SEMAPHORE_SENDER_NAME', 'MAO-ECHAGUE'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile
    |--------------------------------------------------------------------------
    | Site key lives on the frontend (VITE_TURNSTILE_SITE_KEY). Only the
    | secret is used here to verify tokens against Cloudflare.
    */
    'turnstile' => [
        'secret' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => env(
            'TURNSTILE_VERIFY_URL',
            'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        ),
    ],

];
