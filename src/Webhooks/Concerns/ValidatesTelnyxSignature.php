<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Webhooks\Concerns;

use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Illuminate\Http\Request;

trait ValidatesTelnyxSignature
{
    /**
     * @param  array<string, mixed>  $config
     */
    protected function assertValidTelnyxSignature(Request $request, array $config): void
    {
        $secret = $config['webhook_secret'] ?? null;
        if (! is_string($secret) || $secret === '') {
            throw new TextoWebhookValidationException('Telnyx webhook_secret missing for webhook validation.');
        }
        $signature = $request->header('Telnyx-Signature-Ed25519');
        $timestamp = $request->header('Telnyx-Timestamp');
        if (! is_string($signature) || ! is_string($timestamp) || $signature === '' || $timestamp === '') {
            throw new TextoWebhookValidationException('Telnyx signature headers missing.');
        }

        $tolerance = (int) ($config['webhook_tolerance_seconds'] ?? 300);
        if (preg_match('/\A[1-9][0-9]{0,10}\z/', $timestamp) !== 1) {
            throw new TextoWebhookValidationException('Telnyx signature timestamp malformed.');
        }
        if ($tolerance > 0) {
            if (abs(time() - (int) $timestamp) > $tolerance) {
                throw new TextoWebhookValidationException('Telnyx webhook timestamp outside allowed tolerance.');
            }
        }
        if (! extension_loaded('sodium')) {
            throw new TextoWebhookValidationException('Sodium extension required for Telnyx signature verification.');
        }
        $signatureBin = base64_decode($signature, true);
        if ($signatureBin === false || strlen($signatureBin) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new TextoWebhookValidationException('Unable to base64 decode Telnyx signature.');
        }
        $publicKey = base64_decode($secret, true);
        if ($publicKey === false) {
            $publicKey = $secret;
        }
        if (! defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES') || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new TextoWebhookValidationException('Invalid Telnyx webhook_secret length.');
        }
        $payload = $timestamp.'|'.$request->getContent();
        $verified = sodium_crypto_sign_verify_detached($signatureBin, $payload, $publicKey);
        if (! $verified) {
            throw new TextoWebhookValidationException('Invalid Telnyx webhook signature.');
        }
    }
}
