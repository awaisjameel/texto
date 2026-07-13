<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Webhooks\Concerns;

use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Illuminate\Http\Request;

trait ValidatesMetaSignature
{
    /** @param array<string, mixed> $config */
    protected function assertValidMetaSignature(Request $request, array $config): void
    {
        $appSecret = $config['app_secret'] ?? null;
        if (! is_string($appSecret) || $appSecret === '') {
            throw new TextoWebhookValidationException('WhatsApp app_secret missing for webhook validation.');
        }
        $signature = $request->header('X-Hub-Signature-256');
        if (! is_string($signature) || $signature === '') {
            throw new TextoWebhookValidationException('X-Hub-Signature-256 header missing.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);
        if (! hash_equals($expected, $signature)) {
            throw new TextoWebhookValidationException('Invalid WhatsApp webhook signature.');
        }
    }
}
