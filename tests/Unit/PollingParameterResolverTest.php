<?php

declare(strict_types=1);

use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Models\Message;
use Awaisjameel\Texto\Support\PollingParameterResolver;

it('returns provider id only for telnyx', function () {
    $m = new Message([
        'driver' => Driver::Telnyx->value,
        'provider_message_id' => 'telnyx-123',
        'metadata' => [],
    ]);
    $args = PollingParameterResolver::fetchStatusArgs(Driver::Telnyx, $m);
    expect($args)->toBe(['telnyx-123']);
});

it('returns provider id and conversation sid for twilio conversation message', function () {
    $m = new Message([
        'driver' => Driver::Twilio->value,
        'provider_message_id' => 'twilio-123',
        'metadata' => ['conversation_sid' => 'CHabcdef0123456789abcdef0123456789'],
    ]);
    $args = PollingParameterResolver::fetchStatusArgs(Driver::Twilio, $m);
    expect($args)->toBe(['twilio-123', 'CHabcdef0123456789abcdef0123456789']);
});

it('returns provider id only for twilio non-conversation message', function () {
    $m = new Message([
        'driver' => Driver::Twilio->value,
        'provider_message_id' => 'twilio-456',
        'metadata' => [],
    ]);
    $args = PollingParameterResolver::fetchStatusArgs(Driver::Twilio, $m);
    expect($args)->toBe(['twilio-456']);
});
