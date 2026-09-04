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
    | Semaphore. Super Admin selects the live provider in the SMS Gateway
    | page; SMS_PROVIDER is the fallback until that override is saved.
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

    /*
    |--------------------------------------------------------------------------
    | PAGASA Panahon (radar overlay)
    |--------------------------------------------------------------------------
    */
    'pagasa' => [
        'radar_enabled' => env('PAGASA_RADAR_ENABLED', true),
        'panahon_base_url' => env('PAGASA_PANAHON_BASE_URL', 'https://cdn.panahon.gov.ph'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bagyo API (cyclone / rainfall advisories)
    |--------------------------------------------------------------------------
    */
    'bagyo' => [
        'base_url' => env('BAGYO_API_BASE_URL', 'https://api.bagyo.io'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Facebook Page (MAO social posts)
    |--------------------------------------------------------------------------
    | Long-lived Page access token with pages_manage_posts. Tokens stay in
    | server env — never collected through the UI (same pattern as SMS keys).
    */
    'facebook' => [
        'page_id' => env('FACEBOOK_PAGE_ID'),
        'page_access_token' => env('FACEBOOK_PAGE_ACCESS_TOKEN'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v21.0'),
        'graph_base_url' => env('FACEBOOK_GRAPH_BASE_URL', 'https://graph.facebook.com'),
    ],

];
