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
];
