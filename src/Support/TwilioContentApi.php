<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Contracts\TwilioContentApiInterface;
use Awaisjameel\Texto\Exceptions\TwilioApiException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TwilioContentApi implements TwilioContentApiInterface
{
    public function __construct(protected string $accountSid, protected string $authToken)
    {
    }

    public function findTemplateByFriendlyName(string $friendlyName): ?array
    {
        try {
            $response = Http::twilio('content')->get('/Content', ['FriendlyName' => $friendlyName]);
        } catch (Throwable $e) {
            Log::warning('Twilio Content API query failed', ['friendly_name' => $friendlyName, 'error' => $e->getMessage()]);
            throw new TwilioApiException('Unable to query Twilio Content templates.', 0, null, ['friendly_name' => $friendlyName]);
        }
        if ($response->failed()) {
            Log::error('Twilio Content API search error', ['friendly_name' => $friendlyName, 'status' => $response->status(), 'body' => $response->body()]);
            throw new TwilioApiException('Content template search failed.', $response->status());
        }
        $records = $response->json('contents') ?? [];
        foreach ($records as $record) {
            if (strcasecmp(Arr::get($record, 'friendly_name', ''), $friendlyName) === 0) {
                return $record;
            }
        }
        return null;
    }

    public function createTemplate(array $definition): string
    {
        $snake = $definition; // expecting snake_case keys already
        $title = $this->convertPayloadToTitleCase($definition);
        $attempts = [
            ['variant' => 'snake_case', 'body' => $snake],
            ['variant' => 'TitleCase', 'body' => $title],
        ];
        foreach ($attempts as $attempt) {
            try {
                $response = Http::twilio('content')->post('/Content', $attempt['body']);
            } catch (Throwable $e) {
                Log::warning('Twilio Content template create HTTP exception', ['variant' => $attempt['variant'], 'error' => $e->getMessage()]);
                continue;
            }
            if ($response->successful()) {
                $sid = $response->json('sid');
                if ($sid) {
                    Log::info('Twilio Content template created', ['friendly_name' => $definition['friendly_name'] ?? null, 'sid' => $sid]);
                    return $sid;
                }
                Log::error('Twilio Content success response missing SID', ['variant' => $attempt['variant'], 'body' => $response->body()]);
                continue;
            }
            Log::warning('Twilio Content template create failed variant', ['variant' => $attempt['variant'], 'status' => $response->status(), 'body' => $response->body()]);
        }
        throw new TwilioApiException('Unable to create Twilio Content template after retries.');
    }

    public function ensureTemplate(string $friendlyName, callable $definitionFactory): string
    {
        $existing = null;
        try {
            $existing = $this->findTemplateByFriendlyName($friendlyName);
        } catch (Throwable $e) {
            Log::debug('Proceeding to create template due to lookup failure.', ['friendly_name' => $friendlyName]);
        }
        if ($existing) {
            $sid = Arr::get($existing, 'sid');
            if ($sid) {
                Log::info('Reusing existing Twilio Content template', ['friendly_name' => $friendlyName, 'sid' => $sid]);
                return $sid;
            }
        }
        $definition = $definitionFactory();
        return $this->createTemplate($definition);
    }

    protected function convertPayloadToTitleCase(array $payload): array
    {
        $map = [
            'friendly_name' => 'FriendlyName',
            'language' => 'Language',
            'variables' => 'Variables',
            'types' => 'Types',
        ];
        $out = [];
        foreach ($payload as $k => $v) {
            $out[$map[$k] ?? $k] = $v;
        }
        return $out;
    }
}
