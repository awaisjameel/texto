### Key Points

-   **Telnyx Messaging API Overview**: Telnyx provides a robust RESTful API for sending and receiving SMS/MMS messages globally, with support for delivery status notifications via webhooks. All interactions use HTTPS POST/GET requests to `https://api.telnyx.com/v2/` endpoints, authenticated via Bearer tokens. Research indicates high reliability for US/Canada numbers, though international support varies by carrier compliance.
-   **Sending SMS/MMS**: Use the `/messages` endpoint to send texts or media-rich messages. SMS is straightforward with `text` parameter; MMS requires `media_urls` array of public HTTPS links (e.g., from S3). Costs are per-segment (~160 chars for GSM-7), typically $0.005 USD outbound.
-   **Webhooks for Events**: Real-time notifications for inbound messages (`message.received`) and delivery statuses (`message.finalized`). Configure via Messaging Profiles in the portal or per-request. Signatures use ED25519 for security—always verify to prevent spoofing.
-   **PHP/Laravel Integration**: Implement raw HTTP calls with Laravel's HTTP client (`Http` facade). For webhooks, create a dedicated route, verify signatures using Sodium extension, and process payloads idempotently to handle retries.
-   **Best Practices**: Use E.164 phone formats (+1XXXXXXXXXX), store API keys in `.env`, handle errors (e.g., 429 rate limits at 100 req/min for sending), and persist media from ephemeral MMS links.

### Authentication

Secure all API calls with a Bearer token from your Telnyx API key (generated in Mission Control Portal > Auth V2 API Keys). Include it in the `Authorization` header: `Bearer YOUR_API_KEY`. Store in Laravel's `.env` as `TELNYX_API_KEY` and access via `config('texto.telnyx.api_key')` or `env()`. Rotate keys regularly and use separate dev/prod keys. Evidence from Telnyx docs confirms this prevents unauthorized access, with 401/403 errors for invalid keys.

### Sending SMS/MMS

**SMS Example (Laravel Controller)**:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SmsController extends Controller
{
    public function sendSms(Request $request)
    {
        $payload = [
            'from' => '+15551234567', // Your Telnyx number
            'to' => $request->to,     // E.164 format
            'text' => $request->message,
        ];

        try {
            $response = Http::baseUrl(config('texto.telnyx.base_uri'))
                ->withToken(config('texto.telnyx.api_key'))
                ->acceptJson()
                ->post('messages', $payload)
                ->json();

            return response()->json($response);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
```

**MMS Extension**: Add `'media_urls' => ['https://example.com/image.jpg']` and optionally `'subject' => 'Photo Update'`. Ensure media <2MB, public, and HTTPS. Download inbound MMS media immediately (expires in 30 days) and rehost on S3 for outbound.

### Configuring Webhooks

Set webhook URLs in the Telnyx Portal (Messaging > Profiles > Edit > Inbound Settings) or per-message via `'webhook_url' => 'https://yourapp.com/webhooks/telnyx'`. Use failover URLs for redundancy. For API management, create profiles via POST `/messaging_profiles` (requires `webhook_url` param). This setup ensures events like deliveries route to your Laravel endpoint.

### Handling Webhooks

Create a route: `Route::post('/webhooks/telnyx', [WebhookController::class, 'handle']);`. Verify signatures (ED25519) to confirm authenticity—use PHP's Sodium for detached signature check. Process `message.received` for inbounds (reply via API) and `message.finalized` for statuses. Always respond with HTTP 200 to acknowledge.

**Signature Verification Middleware Example**:

```php
use Closure;
use Illuminate\Http\Request;
use SodiumException;

class VerifyTelnyxWebhook
{
    public function handle(Request $request, Closure $next)
    {
        $payload = $request->getContent(); // Raw body
        $timestamp = $request->header('Telnyx-Timestamp');
        $signature = $request->header('Telnyx-Signature-Ed25519');

        if (!$timestamp || !$signature) {
            return response('Unauthorized', 401);
        }

        // Check timestamp freshness (e.g., <5 min)
        if (abs(time() - $timestamp) > 300) {
            return response('Timestamp invalid', 400);
        }

        $message = $timestamp . '.' . $payload;
        $pubKey = base64_decode(env('TELNYX_WEBHOOK_SECRET'));
        $sig = base64_decode($signature);

        if (!sodium_crypto_sign_verify_detached($sig, $message, $pubKey)) {
            return response('Signature invalid', 401);
        }

        return $next($request);
    }
}
```

Apply to route: `->middleware(VerifyTelnyxWebhook::class)`. Get public key from Portal > Settings > Keys & Credentials.

---

### Comprehensive Guide to Telnyx Messaging API Integration in PHP/Laravel

#### Introduction to Telnyx API for Messaging

Telnyx's v2 API offers a scalable platform for SMS/MMS communications, emphasizing compliance (e.g., TCPA via TCR campaigns) and global reach. Core endpoints focus on message creation, retrieval, and event-driven webhooks. Unlike SDK wrappers, this guide uses raw HTTP for flexibility in Laravel (v10+), leveraging Guzzle for requests and Sodium for crypto. Base URL: `https://api.telnyx.com/v2/`. Rate limits: ~100 req/min for sends (burst up to 500), with 429 responses on exceedance—implement exponential backoff.

Key benefits include real-time delivery receipts, media handling, and idempotent processing to mitigate duplicates. For Laravel, integrate via services/controllers, queue jobs for async sends (e.g., `SendSmsJob`), and Eloquent for logging. Always format phones as E.164; validate with Laravel's `phone` rule.

#### Authentication and Security Fundamentals

Telnyx mandates Bearer auth for all calls. Generate keys in the Portal (no scopes—full access). In Laravel, publish a config file:

```php
// config/texto.php (telnyx section)
return [
    'api_key' => env('TELNYX_API_KEY'),
    'messaging_profile_id' => env('TELNYX_MESSAGING_PROFILE_ID'),
    'from_number' => env('TELNYX_FROM_NUMBER'),
    'webhook_secret' => env('TELNYX_WEBHOOK_SECRET'), // Base64-encoded ED25519 public key
    'base_uri' => env('TELNYX_BASE_URI', 'https://api.telnyx.com/v2/'),
    'timeout' => (float) env('TELNYX_TIMEOUT', 10),
    'connect_timeout' => (float) env('TELNYX_CONNECT_TIMEOUT', 5),
];
```

Load via `config('texto.telnyx.api_key')`. Best practices: Encrypt in `.env`, use Laravel Vault for prod, handle 401/403 with retries. No OAuth—simple token suits server-to-server.

Error handling follows standard HTTP: 4xx client errors (e.g., 400 invalid payload), 5xx server (retryable). Parse JSON responses:

```php
if ($response->getStatusCode() >= 400) {
    $error = json_decode($response->getBody(), true)['errors'][0] ?? 'Unknown error';
    // Log and throw
}
```

Common messaging errors: 422 (invalid number), 402 (insufficient funds).

#### Sending Messages: SMS and MMS Endpoints

The primary endpoint is **POST /messages** for outbound sends. Retrieve via **GET /messages** (list, paginated) or **GET /messages/{id}** (details).

| Parameter            | Type          | Required                   | Description                                                                  | Example                                  |
| -------------------- | ------------- | -------------------------- | ---------------------------------------------------------------------------- | ---------------------------------------- |
| from                 | string        | Yes                        | Sender: Long code, short code, or alphanumeric (with `messaging_profile_id`) | `+15551234567`                           |
| to                   | string/array  | Yes                        | Recipient(s): E.164                                                          | `+15559876543`                           |
| text                 | string        | Yes (SMS)                  | Body: Max 1600 chars; auto-segments                                          | `"Welcome aboard!"`                      |
| media_urls           | array[string] | No (MMS)                   | Public HTTPS media URLs (1-10, <5MB each)                                    | `["https://s3.amazonaws.com/image.jpg"]` |
| subject              | string        | No (MMS)                   | MMS title (replaces text if set)                                             | `"Event Photo"`                          |
| webhook_url          | string        | No                         | Per-message callback override                                                | `https://app.com/dlr`                    |
| messaging_profile_id | string        | Conditional (alphanumeric) | Profile for sender validation                                                | `16fd2706-8baf-433b-82eb-8c7fada847da`   |
| tags                 | array[string] | No                         | Custom metadata                                                              | `["campaign:promo"]`                     |

**Full Laravel Service Example for Sending**:

```php
// app/Services/TelnyxService.php
namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class TelnyxService
{
    protected string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('texto.telnyx.api_key');
    }

    public function sendMessage(array $data): array
    {
        $payload = [
            'from' => $data['from'],
            'to' => $data['to'],
            'text' => $data['text'] ?? null,
            'media_urls' => $data['media_urls'] ?? [],
            'subject' => $data['subject'] ?? null,
            'webhook_url' => $data['webhook_url'] ?? null,
        ];

        try {
            return Http::baseUrl(config('texto.telnyx.base_uri'))
                ->withToken($this->apiKey)
                ->acceptJson()
                ->throw()
                ->post('messages', $payload)
                ->json('data');
        } catch (RequestException $e) {
            $status = $e->response->status() ?? 500;
            throw new \Exception("Send failed: {$status}", $status);
        }
    }

    public function getMessage(string $id): array
    {
        return Http::baseUrl(config('texto.telnyx.base_uri'))
            ->withToken($this->apiKey)
            ->acceptJson()
            ->throw()
            ->get("messages/{$id}")
            ->json('data');
    }
}
```

**Response Schema (Simplified Table)**:

| Field       | Type      | Description                                 | Example                                |
| ----------- | --------- | ------------------------------------------- | -------------------------------------- |
| id          | string    | Unique ID                                   | `b0c7e8cb-6227-4c74-9f32-c7f80c30934b` |
| status      | string    | Current state (queued, delivered, failed)   | `delivered`                            |
| cost        | object    | Billing:`{amount: 0.0051, currency: "USD"}` | See above                              |
| errors      | array     | Issues (empty on success)                   | `[]`                                   |
| received_at | timestamp | Creation time                               | `2025-11-10T21:16:00Z`                 |

For MMS, pre-upload media to persistent storage (e.g., Laravel's Filesystem to S3) and provide signed URLs. Inbound MMS media requires immediate download—use Guzzle streams to avoid memory bloat.

#### Webhook Configuration and Event Types

Webhooks notify via POST to your URL on events. Hierarchy: Per-message > Profile > Default (none). Configure profiles via Portal or **POST /messaging_profiles**:

```json
{
    "name": "Default Profile",
    "webhook_url": "https://yourapp.com/webhooks/telnyx",
    "webhook_failover_url": "https://backup.com/webhooks",
    "inbound": { "url": "https://yourapp.com/inbound" }
}
```

Assign to numbers in Portal > Numbers > Edit > Messaging Profile.

**Key Event Types for Messaging**:

| Event                  | Direction | Description              | Trigger                           |
| ---------------------- | --------- | ------------------------ | --------------------------------- |
| message.received       | Inbound   | New SMS/MMS arrived      | User texts your number            |
| message.finalized      | Outbound  | Delivery complete/failed | Status update (delivered, failed) |
| message.queued         | Outbound  | Accepted for processing  | Post-send                         |
| message.sending_failed | Outbound  | Upstream error           | Carrier rejection                 |

Payloads wrap in `{data: {event_type, payload: {message details}}, meta: {attempt, delivered_to}}`.

#### Handling Incoming Webhooks in Laravel

Expose a public HTTPS endpoint (use Forge/ Vapor for prod). Exclude from CSRF: `VerifyCsrfToken` middleware's `$except = ['/webhooks/telnyx'];`. Use queues for processing: Dispatch `ProcessTelnyxWebhookJob` on receipt.

**Controller Example**:

```php
// app/Http/Controllers/WebhookController.php
namespace App\Http\Controllers;

use App\Jobs\ProcessTelnyxWebhookJob;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Middleware handles verification
        ProcessTelnyxWebhookJob::dispatch($request->all(), $request->getContent());
        return response('', 200); // Always ack
    }

    public function processInbound(array $payload)
    {
        $message = $payload['data']['payload'];
        if ($message['direction'] === 'inbound') {
            // Reply: Use TelnyxService to send back
            $this->telnyxService->sendMessage([
                'from' => $message['to'][0]['phone_number'],
                'to' => $message['from']['phone_number'],
                'text' => "Echo: {$message['text']}",
            ]);
            // Log to DB: Message::create([...])
        } elseif (isset($message['status']) && $message['status'] === 'delivery_failed') {
            // Handle failure: Retry logic or notify
        }
    }
}
```

For MMS inbound: Loop `$message['media']`, download each `url` (Guzzle GET), validate `hash_sha256`, upload to S3:

```php
foreach ($message['media'] as $media) {
    $content = $this->client->get($media['url'])->getBody()->getContents();
    if (!hash_equals($media['hash_sha256'], hash('sha256', $content))) {
        // Integrity fail
    }
    $s3Path = Storage::disk('s3')->put('media/' . basename($media['url']), $content);
    $publicUrl = Storage::disk('s3')->url($s3Path);
    // Use in reply
}
```

Idempotency: Check `payload.id` in DB before processing.

#### Advanced Topics: Rate Limits, Media, and Compliance

-   **Rate Limits**: Sending: 100/min sustained, 500 burst. Monitor via headers `X-Ratelimit-Remaining`. Queue in Laravel Horizon for scaling.
-   **Media Handling**: Ephemeral inbound URLs—stream download: `$stream = GuzzleHttp\Psr7\Utils::streamFor($response->getBody());`. Supported: JPEG, PNG, GIF (<carrier limits, e.g., 600KB AT&T).
-   **Compliance**: Register TCR campaigns for A2P 10DLC. Include `tcr_campaign_id` in sends; check `tcr_campaign_registered` in responses.
-   **Testing**: Use Portal's test numbers, ngrok for local webhooks. Simulate with Postman (add headers).

#### Comparison of SMS vs. MMS

| Aspect   | SMS                    | MMS                               |
| -------- | ---------------------- | --------------------------------- | --- |
| Cost     | ~$0.005/segment        | ~$0.02 + media fees               |     |
| Content  | Text only (1600 chars) | Text + media (up to 10 files)     |
| Delivery | 99%+ success           | Carrier-dependent; higher failure |
| Webhook  | Status only            | Includes media metadata           |
| Use Case | Alerts, OTP            | Marketing, sharing photos         |

This integration enables robust, event-driven messaging. For bulk sends, batch via queues; monitor costs via GET `/account/billing`.

### Key Citations

-   [Telnyx Authentication Docs](https://developers.telnyx.com/development/api-fundamentals/authentication)
-   [Send Message API](https://developers.telnyx.com/docs/messaging/messages/send-message)
-   [Receiving Webhooks](https://developers.telnyx.com/docs/messaging/messages/receiving-webhooks)
-   [MMS Guide](https://developers.telnyx.com/docs/messaging/messages/send-receive-mms)
-   [Webhook Sign Key Guide](https://support.telnyx.com/en/articles/8370064-update-webhook-sign-key-guide)
-   [Laravel Webhook Handling](https://medium.com/@prevailexcellent/how-to-handle-webhook-in-laravel-two-ways-and-the-best-way-90abfa7e1a39)
