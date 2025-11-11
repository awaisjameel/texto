<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Deprecated legacy helper retained for backward compatibility; now pure HTTP (no twilio/sdk).

/**
 * Lightweight Twilio Content API helper for syncing / creating templates.
 * Only implements the subset needed by Texto for SMS + MMS defaults.
 */
class TwilioContentService
{
    private const BASE_URL = 'https://content.twilio.com/v1';

    public function __construct(protected string $accountSid, protected string $authToken) {}

    /**
     * Finds template by friendly name. Returns raw record array or null.
     */
    public function findByFriendlyName(string $friendlyName): ?array
    {
        try {
            $response = $this->http()->get(self::BASE_URL.'/Content', [
                'FriendlyName' => $friendlyName,
            ]);
        } catch (Throwable $e) {
            Log::warning('Twilio Content API query failed', ['friendly_name' => $friendlyName, 'error' => $e->getMessage()]);
            throw new Exception('Unable to query Twilio Content templates.', 0, $e);
        }

        if ($response->failed()) {
            Log::error('Twilio Content API search error', [
                'friendly_name' => $friendlyName,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new Exception('Content template search failed.');
        }

        $records = $response->json('contents') ?? [];
        foreach ($records as $record) {
            if (strcasecmp(Arr::get($record, 'friendly_name', ''), $friendlyName) === 0) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Creates template with fallback casing strategy. Returns SID.
     * $definition shape (snake_case): friendly_name, language, variables[], types[]
     */
    public function createTemplate(array $definition): string
    {
        $snake = $definition;
        $title = $this->convertPayloadToTitleCase($definition);
        $attempts = [
            ['variant' => 'snake_case', 'body' => $snake],
            ['variant' => 'TitleCase', 'body' => $title],
        ];

        foreach ($attempts as $attempt) {
            try {
                $response = $this->http()->post(self::BASE_URL.'/Content', $attempt['body']);
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
            Log::warning('Twilio Content template create failed variant', [
                'variant' => $attempt['variant'],
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        throw new Exception('Unable to create Twilio Content template after retries.');
    }

    /**
     * Ensures a template SID for the given friendly name; creates if missing using provided default definition.
     */
    public function ensureTemplate(string $friendlyName, callable $definitionFactory): string
    {
        $existing = null;
        try {
            $existing = $this->findByFriendlyName($friendlyName);
        } catch (Throwable $e) {
            // If lookup fails unexpectedly we still attempt create to proceed.
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

    protected function http()
    {
        return Http::withBasicAuth($this->accountSid, $this->authToken)->acceptJson()->asJson();
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
