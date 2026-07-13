<?php

declare(strict_types=1);

use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Awaisjameel\Texto\Webhooks\WhatsappWebhookHandler;
use Illuminate\Http\Request;

function whatsappWebhookRequest(array $payload, array $headers = []): Request
{
    return Request::create('/texto/webhook/whatsapp', 'POST', [], [], [], $headers, json_encode($payload, JSON_THROW_ON_ERROR));
}

beforeEach(function () {
    config()->set('texto.testing.skip_webhook_validation', true);
});

it('maps inbound media and status batches', function () {
    $payload = [
        'entry' => [
            [
                'changes' => [
                    [
                        'field' => 'messages',
                        'value' => [
                            'metadata' => ['display_phone_number' => '15550003333'],
                            'contacts' => [['wa_id' => '923001234567', 'profile' => ['name' => 'Awais']]],
                            'messages' => [[
                                'from' => '923001234567', 'id' => 'wamid.inbound', 'type' => 'image',
                                'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg', 'sha256' => 'hash', 'caption' => 'Photo'],
                            ]],
                            'statuses' => [[
                                'id' => 'wamid.outbound', 'status' => 'delivered', 'recipient_id' => '923001234567',
                                'conversation' => ['id' => 'c1', 'origin' => ['type' => 'utility']],
                                'pricing' => ['billable' => true, 'category' => 'utility'],
                            ]],
                        ],
                    ],
                ],
            ],
        ],
    ];
    $results = (new WhatsappWebhookHandler)->handleBatch(whatsappWebhookRequest($payload));

    expect($results)->toHaveCount(2);
    expect($results[0]->direction)->toBe(Direction::Received);
    expect($results[0]->from?->e164)->toBe('+923001234567');
    expect($results[0]->mediaUrls)->toBe(['whatsapp-media://media-1']);
    expect($results[0]->metadata['profile_name'])->toBe('Awais');
    expect($results[1]->status)->toBe(MessageStatus::Delivered);
    expect($results[1]->metadata['pricing']['category'])->toBe('utility');
});

it('validates the raw Meta signature', function () {
    config()->set('texto.testing.skip_webhook_validation', false);
    $payload = ['entry' => []];
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $request = Request::create('/texto/webhook/whatsapp', 'POST', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'app-secret'),
    ], $raw);

    expect((new WhatsappWebhookHandler(['app_secret' => 'app-secret']))->handleBatch($request))->toBe([]);
    (new WhatsappWebhookHandler(['app_secret' => 'app-secret']))->handleBatch(whatsappWebhookRequest($payload));
})->throws(TextoWebhookValidationException::class);

it('preserves WhatsApp read receipts distinctly from delivered receipts', function () {
    $payload = [
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => ['statuses' => [['id' => 'wamid.read', 'status' => 'read']]],
            ]],
        ]],
    ];

    $results = (new WhatsappWebhookHandler)->handleBatch(whatsappWebhookRequest($payload));

    expect($results[0]->status)->toBe(MessageStatus::Read);
});
