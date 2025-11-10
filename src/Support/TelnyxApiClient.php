<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TelnyxApiClient
{
    private PendingRequest $http;

    public function __construct(?string $apiKey, ?PendingRequest $http = null)
    {
        if ($apiKey === null || $apiKey === '') {
            throw new \InvalidArgumentException('Telnyx API key is required.');
        }

        $this->http = ($http ?? $this->defaultRequest())
            ->withToken($apiKey)
            ->timeout((int) config('texto.telnyx.timeout', 15));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function sendMessage(array $payload): array
    {
        return $this->execute(fn () => $this->http->post('messages', $payload));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function retrieveMessage(string $messageId): array
    {
        return $this->execute(fn () => $this->http->get("messages/{$messageId}"));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    protected function execute(callable $callback): array
    {
        /** @var Response $response */
        $response = $callback();
        $response->throw();

        $json = $response->json();
        if (! is_array($json)) {
            throw RequestException::create($response);
        }

        return $json;
    }

    private function defaultRequest(): PendingRequest
    {
        return Http::baseUrl('https://api.telnyx.com/v2/')
            ->asJson()
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => $this->userAgent(),
            ]);
    }

    private function userAgent(): string
    {
        $laravel = 'unknown';
        if (function_exists('app')) {
            $app = app();
            if (is_object($app) && method_exists($app, 'version')) {
                $laravel = (string) $app->version();
            }
        }

        return sprintf('texto-laravel/%s (Laravel %s)', $this->packageVersion(), $laravel);
    }

    private function packageVersion(): string
    {
        $version = class_exists(\Composer\InstalledVersions::class)
            ? \Composer\InstalledVersions::getPrettyVersion('awaisjameel/texto')
            : null;

        return $version ?? 'dev';
    }
}
