<?php

return [
    'account_sid' => env('TWILIO_ACCOUNT_SID'),
    'auth_token' => env('TWILIO_AUTH_TOKEN'),
    'from_number' => env('TWILIO_FROM_NUMBER'),
    'use_conversations' => env('TWILIO_USE_CONVERSATIONS', true),

    'base_urls' => [
        'messaging' => 'https://api.twilio.com/2010-04-01',
        'conversations' => 'https://conversations.twilio.com/v1',
        'content' => 'https://content.twilio.com/v1',
    ],

    'timeout' => env('TWILIO_HTTP_TIMEOUT', 15),

    'retry' => [
        'max_attempts' => env('TWILIO_RETRY_ATTEMPTS', env('TEXTO_RETRY_ATTEMPTS', 3)),
        'backoff_start_ms' => env('TWILIO_RETRY_BACKOFF_START', env('TEXTO_RETRY_BACKOFF_START', 200)),
    ],
];
