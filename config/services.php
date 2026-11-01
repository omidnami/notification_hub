<?php

return [
    'postmark' => ['key' => env('POSTMARK_API_KEY')],
    'resend' => ['key' => env('RESEND_API_KEY')],
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
    'bale' => [
        'token' => env('BALE_BOT_TOKEN'),
        'username' => env('BALE_BOT_USERNAME'),
        'webhook_secret' => env('BALE_WEBHOOK_SECRET'),
    ],
    'rubika' => [
        'token' => env('RUBIKA_BOT_TOKEN'),
        'username' => env('RUBIKA_BOT_USERNAME'),
        'webhook_secret' => env('RUBIKA_WEBHOOK_SECRET'),
    ],
    'eitaa' => [
        'token' => env('EITAA_APP_TOKEN'),
        'app_url' => env('EITAA_APP_URL'),
        'message_budget' => (int) env('EITAA_MESSAGE_BUDGET', 50000),
    ],
    'email_gateway' => [
        'enabled' => (bool) env('EMAIL_GATEWAY_ENABLED', false),
        'url' => env('EMAIL_GATEWAY_URL'),
        'secret' => env('EMAIL_GATEWAY_SECRET'),
        'google_client_id' => env('GMAIL_OAUTH_CLIENT_ID'),
        'google_client_secret' => env('GMAIL_OAUTH_CLIENT_SECRET'),
        'google_redirect_uri' => env('GMAIL_OAUTH_REDIRECT_URI'),
        'user_daily_limit' => (int) env('EMAIL_USER_DAILY_LIMIT', 50),
        'project_daily_limit' => (int) env('EMAIL_PROJECT_DAILY_LIMIT', 30),
    ],
    'webpush' => [
        'subject' => env('WEBPUSH_VAPID_SUBJECT'),
        'public_key' => env('WEBPUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('WEBPUSH_VAPID_PRIVATE_KEY'),
        'use_gateway' => (bool) env('WEBPUSH_USE_GATEWAY', false),
        'gateway_enabled' => (bool) env('WEBPUSH_GATEWAY_ENABLED', false),
        'gateway_url' => env('WEBPUSH_GATEWAY_URL'),
        'gateway_secret' => env('WEBPUSH_GATEWAY_SECRET'),
    ],
    'ippanel' => [
        'key' => env('IPPANEL_API_KEY'),
        'from_number' => env('IPPANEL_FROM_NUMBER'),
        'project_daily_limit' => (int) env('IPPANEL_PROJECT_DAILY_LIMIT', 100),
        'user_daily_limit' => (int) env('IPPANEL_USER_DAILY_LIMIT', 300),
        'recipient_hourly_limit' => (int) env('IPPANEL_RECIPIENT_HOURLY_LIMIT', 5),
    ],
    'telegram' => [
        // core: Iran with DB; worker: Germany with Bot API; otherwise disabled.
        'role' => env('TELEGRAM_ROLE', 'disabled'),
        'username' => env('TELEGRAM_BOT_USERNAME'),
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),
        'gateway_secret' => env('TELEGRAM_GATEWAY_SECRET'),
        'gateway_url' => env('TELEGRAM_GATEWAY_URL'),
        'core_url' => env('TELEGRAM_CORE_URL'),
        'user_daily_limit' => (int) env('TELEGRAM_USER_DAILY_LIMIT', 300),
        'project_daily_limit' => (int) env('TELEGRAM_PROJECT_DAILY_LIMIT', 150),
    ],
];
