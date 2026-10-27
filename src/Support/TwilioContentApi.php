<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Contracts\TwilioContentApiInterface;
use Awaisjameel\Texto\Enums\Driver;
use Illuminate\Http\Client\PendingRequest;

class TwilioContentApi implements TwilioContentApiInterface
{
    /** @var array<string, mixed> */
    protected array $config;

    /** @param array<string, mixed>|null $config */
    public function __construct(protected string $accountSid, protected string $authToken, ?array $config = null)
    {
        if ($accountSid === '' || $authToken === '') {
            throw new \InvalidArgumentException('TwilioContentApi requires non-empty credentials.');
        }
        $this->config = array_replace($config ?? Configuration::forDriver(Driver::Twilio), ['account_sid' => $accountSid, 'auth_token' => $authToken]);
    }

    protected function http(): PendingRequest
    {
        return ProviderHttp::twilio('content', $this->config);
    }

    public function findTemplateByFriendlyName(string $friendlyName): ?array
    {
        foreach (TwilioPaginator::records($this->http(), ProviderHttp::twilioBaseUrl('content', $this->config), '/Content', 'contents') as $record) {
            $name = $record['friendly_name'] ?? null;
            if (is_string($name) && strcasecmp($name, $friendlyName) === 0) {
                JsonResponse::requiredText($record, 'sid');

                return $record;
            }
        }

        return null;
    }

    public function createTemplate(array $definition): string
    {
        // The documented Content API accepts snake_case JSON. Retrying an uncertain
        // creation with a second key-casing variant can create duplicate templates.
        $response = $this->http()->post('/Content', $definition);
        $data = TwilioResponse::read($response, 'createTemplate', requiredField: 'sid');

        return JsonResponse::requiredText($data, 'sid');
    }

    public function ensureTemplate(string $friendlyName, callable $definitionFactory): string
    {
        $existing = $this->findTemplateByFriendlyName($friendlyName);
        if ($existing !== null) {
            return JsonResponse::requiredText($existing, 'sid');
        }

        return $this->createTemplate($definitionFactory());
    }
}
