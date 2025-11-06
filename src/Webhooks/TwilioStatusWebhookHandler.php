<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Webhooks;

use Awaisjameel\Texto\Contracts\WebhookHandlerInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Awaisjameel\Texto\Support\StatusMapper;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Illuminate\Http\Request;
use Twilio\Security\RequestValidator;

class TwilioStatusWebhookHandler implements WebhookHandlerInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = empty($config) ? (config('texto.twilio') ?? []) : $config;
    }

    public function handle(Request $request): WebhookProcessingResult
    {
        $skip = config('texto.testing.skip_webhook_validation', false) && app()->environment('testing');
        $token = $this->config['auth_token'] ?? null;
        if (! $token) {
            throw new TextoWebhookValidationException('Twilio auth token missing for status webhook validation.');
        }
        if (! $skip) {
            $validator = new RequestValidator($token);
            $signature = $request->header('X-Twilio-Signature');
            $url = $request->fullUrl();
            if (! $signature || ! $validator->validate($signature, $url, $request->all())) {
                throw new TextoWebhookValidationException('Invalid Twilio status webhook signature.');
            }
        }

        $statusRaw = $request->input('MessageStatus');
        $providerId = $request->input('MessageSid');
        $status = StatusMapper::map(Driver::Twilio, $statusRaw, null);

        return WebhookProcessingResult::status(Driver::Twilio, $providerId, $status, []);
    }
}
