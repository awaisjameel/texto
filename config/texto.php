<?php

declare(strict_types=1);

return [
    'driver' => env('TEXTO_DRIVER', 'twilio'),
    'store_messages' => env('TEXTO_STORE_MESSAGES', true),
    'queue' => env('TEXTO_QUEUE', false),
    'retry' => [
        'max_attempts' => env('TEXTO_RETRY_ATTEMPTS', 3),
        'backoff_start_ms' => env('TEXTO_RETRY_BACKOFF_START', 200),
    ],
    'twilio' => [
        'base_urls' => [
            'messaging' => 'https://api.twilio.com/2010-04-01',
            'conversations' => 'https://conversations.twilio.com/v1',
            'content' => 'https://content.twilio.com/v1',
        ],
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from_number' => env('TWILIO_FROM_NUMBER'),
        'use_conversations' => env('TWILIO_USE_CONVERSATIONS', false),
        'timeout' => env('TWILIO_HTTP_TIMEOUT', 30),

        // Optional explicit template
        'sms_template_friendly_name' => env('TWILIO_SMS_TEMPLATE_FRIENDLY_NAME', 'texto_sms_template'),
        'mms_template_friendly_name' => env('TWILIO_MMS_TEMPLATE_FRIENDLY_NAME', 'texto_mms_template'),
        // Optional explicit template SIDs (skip auto discovery/creation if provided)
        'sms_template_sid' => env('TWILIO_SMS_TEMPLATE_SID'),
        'mms_template_sid' => env('TWILIO_MMS_TEMPLATE_SID'),
        // Prefix used when creating conversations (FriendlyName). Kept short for readability.
        'conversation_prefix' => env('TWILIO_CONVERSATION_PREFIX', 'Texto'),
        // Default webhook URL for new Conversations (can be overridden per send via metadata['webhook_url'])
        'conversation_webhook_url' => env('TWILIO_CONVERSATION_WEBHOOK_URL'),
    ],
    'telnyx' => [
        'base_url' => env('TELNYX_BASE_URL', 'https://api.telnyx.com/v2/'),
        'api_key' => env('TELNYX_API_KEY'),
        'messaging_profile_id' => env('TELNYX_MESSAGING_PROFILE_ID'),
        'from_number' => env('TELNYX_FROM_NUMBER'),
        'webhook_secret' => env('TELNYX_WEBHOOK_SECRET'),
        'timeout' => env('TELNYX_HTTP_TIMEOUT', 30),
    ],
    'webhook' => [
        'secret' => env('TEXTO_WEBHOOK_SECRET'),
        'rate_limit' => env('TEXTO_WEBHOOK_RATE_LIMIT', 60), // per minute
    ],
    'testing' => [
        'skip_webhook_validation' => env('TEXTO_TESTING_SKIP_WEBHOOK_VALIDATION', false),
    ],
    'validation' => [
        'region' => env('TEXTO_DEFAULT_REGION', 'US'),
    ],
    'failover' => [
        'enabled' => false, // future feature placeholder
    ],
    'status_polling' => [
        'enabled' => env('TEXTO_STATUS_POLL_ENABLED', false),
        'min_age_seconds' => env('TEXTO_STATUS_POLL_MIN_AGE', 60), // only poll messages older than this
        'queued_max_attempts' => env('TEXTO_STATUS_POLL_QUEUED_MAX_ATTEMPTS', 2), // for queued messages without provider id
        'max_attempts' => env('TEXTO_STATUS_POLL_MAX_ATTEMPTS', 5), // per message
        'backoff_seconds' => env('TEXTO_STATUS_POLL_BACKOFF', 300), // wait this after last poll before retry
        'batch_limit' => env('TEXTO_STATUS_POLL_BATCH', 100), // max messages per run
    ],
];
