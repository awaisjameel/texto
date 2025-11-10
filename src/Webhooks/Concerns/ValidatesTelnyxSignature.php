<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Webhooks\Concerns;

use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Illuminate\Http\Request;

trait ValidatesTelnyxSignature
{
    protected function assertValidTelnyxSignature(Request $request, array $config): void
    {
        $secret = $config['webhook_secret'] ?? null;
        if (! $secret) {
            throw new TextoWebhookValidationException('Telnyx webhook_secret missing for webhook validation.');
        }
        $signature = $request->header('Telnyx-Signature-Ed25519');
        $timestamp = $request->header('Telnyx-Signature-Timestamp');
        if (! $signature || ! $timestamp) {
            throw new TextoWebhookValidationException('Telnyx signature headers missing.');
        }
        if (! extension_loaded('sodium')) {
            throw new TextoWebhookValidationException('Sodium extension required for Telnyx signature verification.');
        }
        $signatureBin = base64_decode($signature, true);
        if ($signatureBin === false) {
            throw new TextoWebhookValidationException('Unable to base64 decode Telnyx signature.');
        }
        $publicKey = base64_decode($secret, true);
        if ($publicKey === false) {
            $publicKey = $secret;
        }
        if (! defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES') || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new TextoWebhookValidationException('Invalid Telnyx webhook_secret length.');
        }
        $payload = $timestamp.'.'.$request->getContent();
        $verified = sodium_crypto_sign_verify_detached($signatureBin, $payload, $publicKey);
        if (! $verified) {
            throw new TextoWebhookValidationException('Invalid Telnyx webhook signature.');
        }
    }
}
