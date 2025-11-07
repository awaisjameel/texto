# Texto

**
Unified, extensible Laravel gateway for sending & receiving SMS/MMS over Twilio & Telnyx.
Batteries included: queueing, retries, events, webhooks, polling, typed value objects.**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/awaisjameel/texto.svg?style=flat-square)](https://packagist.org/packages/awaisjameel/texto)
[![Tests](https://img.shields.io/github/actions/workflow/status/awaisjameel/texto/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/awaisjameel/texto/actions?query=workflow%3Atests+branch%3Amain)
[![Downloads](https://img.shields.io/packagist/dt/awaisjameel/texto.svg?style=flat-square)](https://packagist.org/packages/awaisjameel/texto)
[![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE.md)

Texto provides a unified, extensible Laravel package for carrier-grade SMS/MMS messaging. Built for Laravel 10–12 (PHP 7.4 / 8.2+), it abstracts provider complexities (Twilio, Telnyx) through consistent contracts and value objects, enabling seamless integration with enterprise messaging workflows.

**Key Features:**

-   **Unified API**: Single interface for sending SMS/MMS across multiple providers
-   **Message Persistence**: Automatic storage of sent and received messages with full metadata
-   **Status Tracking**: Real-time delivery status updates via webhooks and fallback polling
-   **Event-Driven**: Rich event system for analytics, notifications, and custom automation
-   **Advanced Twilio Support**: Conversations API with auto-provisioned content templates
-   **Reliability**: Exponential backoff retry, queue-based async processing, and graceful degradation
-   **Security**: Webhook signature validation, rate limiting, and shared secret protection
-   **Extensibility**: Plugin architecture for adding new messaging providers

---

## Table of Contents

1. Motivation & Philosophy
2. Feature Overview
3. Quick Start
4. Installation
5. Configuration (`config/texto.php`)
6. Usage Examples
7. Queueing & Async Flow
8. Events & Observability
9. Data Model & Persistence
10. Webhooks (Inbound + Status)
11. Security (Signatures, Secrets, Rate Limiting)
12. Status Polling (Adaptive Fallback)
13. Retry & Backoff Strategy
14. Twilio Conversations & Content Templates
15. Extending / Custom Drivers
16. Value Objects & Enums
17. Console Commands
18. Testing, Fakes & Local Development
19. Architecture Overview
20. Troubleshooting & FAQ
21. Roadmap
22. Contributing
23. Security Policy
24. License & Credits

---

## 1. Motivation & Philosophy

Building messaging features in Laravel applications often involves wrestling with provider-specific APIs that have inconsistent interfaces, error handling, and webhook formats. Without proper abstraction, code becomes littered with conditional logic that breaks when switching providers or adding new ones.

Texto solves this by providing a clean, consistent interface that:

-   **Eliminates Provider Lock-in**: Switch between Twilio, Telnyx, or custom providers with minimal code changes
-   **Ensures Type Safety**: Strongly typed enums and value objects prevent common mistakes
-   **Promotes Clean Architecture**: Clear separation between sending, persistence, and status tracking
-   **Enables Observability**: Comprehensive events and logging for monitoring and debugging
-   **Handles Edge Cases**: Built-in retry logic, queueing, and fallback polling for reliability
-   **Prioritizes Security**: Webhook validation, rate limiting, and shared secret protection

The philosophy is simple: messaging should be a first-class citizen in your Laravel app, not an afterthought that requires constant maintenance.

---

## 2. Feature Overview

### Core Messaging

-   **SMS & MMS Support**: Send text messages and media attachments through Twilio and Telnyx
-   **Unified API**: Single `Texto::send()` method works across all providers
-   **Phone Number Validation**: Automatic E.164 formatting and validation using libphonenumber
-   **Media Handling**: Support for multiple media URLs per message

### Reliability & Performance

-   **Queue Integration**: Async message sending with Laravel queues for high-throughput applications
-   **Retry Logic**: Exponential backoff for transient API failures
-   **Status Polling**: Fallback polling when webhooks are delayed or unavailable
-   **Rate Limiting**: Built-in protection against webhook abuse

### Advanced Twilio Features

-   **Conversations API**: Rich conversation management with participant tracking
-   **Content Templates**: Auto-provisioning and reuse of SMS/MMS templates
-   **Template Variables**: Dynamic content insertion for personalized messaging

### Observability & Events

-   **Event System**: Four key events (`MessageSent`, `MessageReceived`, `MessageFailed`, `MessageStatusUpdated`)
-   **Structured Logging**: Comprehensive logging for debugging and monitoring
-   **Metadata Capture**: Rich metadata storage including costs, segments, and custom data

### Security & Compliance

-   **Webhook Validation**: Signature verification for Twilio, shared secret headers
-   **Rate Limiting**: Configurable per-minute limits on webhook endpoints
-   **Data Persistence**: Optional message storage with configurable retention

### Developer Experience

-   **Type Safety**: Strongly typed enums and value objects
-   **Extensible Architecture**: Plugin system for custom providers
-   **Testing Support**: Fake drivers and webhook validation skipping for tests
-   **Laravel Integration**: Service provider auto-discovery and facade registration

---

## 3. Quick Start

```bash
composer require awaisjameel/texto
php artisan texto:install   # publishes config + migration & runs migrate

php artisan texto:test-send +15551234567 "Hello from Texto"
```

```php
use Texto; // facade alias configured automatically

Texto::send('+15551234567', 'Hello world');
```

---

## 4. Installation

### Prerequisites

Before installing Texto, ensure your Laravel application meets these requirements:

-   **Laravel**: 10.0, 11.0, or 12.0
-   **PHP**: 7.4 or higher (8.2+ recommended)
-   **Database**: MySQL, PostgreSQL, SQLite, or SQL Server
-   **Queue System**: Any Laravel-supported queue driver (Database recommended for production)

### Quick Installation

The fastest way to get started:

```bash
composer require awaisjameel/texto
php artisan texto:install
```

This command will:

-   Publish the configuration file to `config/texto.php`
-   Publish and run the database migration
-   Register the service provider and facade

### Manual Installation

For more control over the installation process:

```bash
# 1. Install the package
composer require awaisjameel/texto

# 2. Publish configuration (optional - auto-published by texto:install)
php artisan vendor:publish --tag=texto-config

# 3. Publish migration (optional - auto-published by texto:install)
php artisan vendor:publish --tag=texto-migrations

# 4. Run migrations
php artisan migrate
```

### Provider Setup

If you're not using package auto-discovery, add the service provider to `config/app.php`:

```php
'providers' => [
    // ... other providers
    Awaisjameel\Texto\TextoServiceProvider::class,
],

'aliases' => [
    // ... other aliases
    'Texto' => Awaisjameel\Texto\Facades\Texto::class,
],
```

### Verification

After installation, verify everything is working:

```bash
php artisan texto:test-send +15551234567 "Hello from Texto!"
```

This will send a test message using your configured provider and settings.

### Environment Variables

```env
# Core
TEXTO_DRIVER=twilio                 # twilio | telnyx
TEXTO_STORE_MESSAGES=true           # disable to skip DB persistence
TEXTO_QUEUE=false                   # true => SendMessageJob async
TEXTO_RETRY_ATTEMPTS=3
TEXTO_RETRY_BACKOFF_START=200       # ms
TEXTO_WEBHOOK_SECRET=               # optional shared secret header
TEXTO_DEFAULT_REGION=US             # for parsing non-E.164 input

# Status polling (optional)
TEXTO_STATUS_POLL_ENABLED=false
TEXTO_STATUS_POLL_MIN_AGE=60
TEXTO_STATUS_POLL_MAX_ATTEMPTS=5
TEXTO_STATUS_POLL_QUEUED_MAX_ATTEMPTS=2
TEXTO_STATUS_POLL_BACKOFF=300
TEXTO_STATUS_POLL_BATCH=100

# Twilio
TWILIO_ACCOUNT_SID=...
TWILIO_AUTH_TOKEN=...
TWILIO_FROM_NUMBER=+15550001111
TWILIO_USE_CONVERSATIONS=true
TWILIO_SMS_TEMPLATE_FRIENDLY_NAME=texto_sms_template
TWILIO_MMS_TEMPLATE_FRIENDLY_NAME=texto_mms_template
TWILIO_CONVERSATION_PREFIX=Texto
TWILIO_CONVERSATION_WEBHOOK_URL=    # optional override

# Telnyx
TELNYX_API_KEY=...
TELNYX_MESSAGING_PROFILE_ID=...
TELNYX_FROM_NUMBER=+15550002222
```

---

## 5. Configuration (`config/texto.php`)

After installation, you'll find the configuration file at `config/texto.php`. Here's a comprehensive guide to all available options:

### Core Settings

| Key              | Default    | Description                                          |
| ---------------- | ---------- | ---------------------------------------------------- |
| `driver`         | `'twilio'` | Active messaging provider (`'twilio'` or `'telnyx'`) |
| `store_messages` | `true`     | Whether to persist messages in the database          |
| `queue`          | `false`    | Enable async message sending via Laravel queues      |
| `default_region` | `'US'`     | Default region for phone number parsing              |

### Retry Configuration

```php
'retry' => [
    'max_attempts' => env('TEXTO_RETRY_ATTEMPTS', 3),
    'backoff_start_ms' => env('TEXTO_RETRY_BACKOFF_START', 200),
],
```

Controls exponential backoff retry behavior for failed API calls:

-   `max_attempts`: Maximum number of retry attempts (default: 3)
-   `backoff_start_ms`: Initial delay in milliseconds (doubles each retry)

### Webhook Security

```php
'webhook' => [
    'secret' => env('TEXTO_WEBHOOK_SECRET'),
    'rate_limit' => env('TEXTO_WEBHOOK_RATE_LIMIT', 60),
],
```

-   `secret`: Optional shared secret for webhook authentication
-   `rate_limit`: Maximum webhook requests per minute (default: 60)

### Status Polling (Fallback)

```php
'status_polling' => [
    'enabled' => env('TEXTO_STATUS_POLL_ENABLED', false),
    'min_age_seconds' => env('TEXTO_STATUS_POLL_MIN_AGE', 60),
    'max_attempts' => env('TEXTO_STATUS_POLL_MAX_ATTEMPTS', 5),
    'queued_max_attempts' => env('TEXTO_STATUS_POLL_QUEUED_MAX_ATTEMPTS', 2),
    'backoff_seconds' => env('TEXTO_STATUS_POLL_BACKOFF', 300),
    'batch_limit' => env('TEXTO_STATUS_POLL_BATCH', 100),
],
```

Configures fallback polling for messages stuck in transient states:

-   `enabled`: Enable/disable polling (default: false)
-   `min_age_seconds`: Minimum age before polling starts
-   `max_attempts`: Maximum polling attempts per message
-   `backoff_seconds`: Delay between polling attempts

### Twilio Configuration

```php
'twilio' => [
    'account_sid' => env('TWILIO_ACCOUNT_SID'),
    'auth_token' => env('TWILIO_AUTH_TOKEN'),
    'from_number' => env('TWILIO_FROM_NUMBER'),
    'use_conversations' => env('TWILIO_USE_CONVERSATIONS', true),
    'sms_template_friendly_name' => env('TWILIO_SMS_TEMPLATE_FRIENDLY_NAME', 'texto_sms_template'),
    'mms_template_friendly_name' => env('TWILIO_MMS_TEMPLATE_FRIENDLY_NAME', 'texto_mms_template'),
    'conversation_prefix' => env('TWILIO_CONVERSATION_PREFIX', 'Texto'),
    'conversation_webhook_url' => env('TWILIO_CONVERSATION_WEBHOOK_URL'),
],
```

Twilio-specific settings for both classic and Conversations API modes.

### Telnyx Configuration

```php
'telnyx' => [
    'api_key' => env('TELNYX_API_KEY'),
    'messaging_profile_id' => env('TELNYX_MESSAGING_PROFILE_ID'),
    'from_number' => env('TELNYX_FROM_NUMBER'),
],
```

Telnyx API credentials and messaging profile configuration.

### Testing Configuration

```php
'testing' => [
    'skip_webhook_validation' => env('TEXTO_TESTING_SKIP_WEBHOOK_VALIDATION', false),
],
```

Settings for testing environments to skip webhook signature validation.

---

## 6. Usage Examples

### Basic SMS Sending

Send a simple text message:

```php
use Texto;

$result = Texto::send('+15551234567', 'Hello from Texto!');

// Returns SentMessageResult with status, provider ID, etc.
echo $result->status->value; // 'sent'
echo $result->providerMessageId; // 'SM1234567890abcdef'
```

### MMS with Media Attachments

Send messages with images, videos, or other media:

```php
$result = Texto::send('+15551234567', 'Check out this photo!', [
    'media_urls' => [
        'https://example.com/image.jpg',
        'https://example.com/video.mp4'
    ]
]);
```

### Per-Message Driver Override

Temporarily use a different provider for specific messages:

```php
// Send via Telnyx instead of default Twilio
$result = Texto::send('+15551234567', 'Via Telnyx', [
    'driver' => 'telnyx'
]);
```

### Custom Sender Number and Metadata

Use different sender numbers and attach custom metadata:

```php
$result = Texto::send('+15551234567', 'Welcome to our service!', [
    'from' => '+15550009999', // Different sender number
    'metadata' => [
        'campaign' => 'welcome_series',
        'user_id' => 12345,
        'priority' => 'high'
    ]
]);
```

### Asynchronous Queue Processing

For high-throughput applications, enable queuing:

```php
// In .env
TEXTO_QUEUE=true

// In code
$result = Texto::send('+15551234567', 'Queued message');
echo $result->status->value; // 'queued'

// Start a queue worker
php artisan queue:work
```

### Controller Response

Return messages directly from controllers (auto-converts to JSON):

```php
class NotificationController extends Controller
{
    public function sendAlert(Request $request)
    {
        $result = Texto::send(
            $request->phone,
            'Alert: ' . $request->message
        );

        // Automatically returns JSON response
        return $result;
    }
}
```

### Event-Driven Processing

Listen to messaging events for analytics and automation:

```php
// In EventServiceProvider
protected $listen = [
    \Awaisjameel\Texto\Events\MessageSent::class => [
        \App\Listeners\LogMessageSent::class,
    ],
    \Awaisjameel\Texto\Events\MessageStatusUpdated::class => [
        \App\Listeners\TrackDeliveryStatus::class,
    ],
];

// Listener example
class TrackDeliveryStatus
{
    public function handle(MessageStatusUpdated $event)
    {
        $result = $event->result;

        // Log delivery metrics
        Log::info('Message delivered', [
            'provider_id' => $result->providerMessageId,
            'delivered_at' => now(),
        ]);
    }
}
```

### Bulk Messaging

Send multiple messages efficiently:

```php
$recipients = ['+15551234567', '+15559876543', '+15551111111'];
$messages = [];

foreach ($recipients as $phone) {
    $messages[] = Texto::send($phone, 'Bulk notification');
}

// Process results
$successful = collect($messages)->where('status.value', 'sent')->count();
```

### International Number Handling

Texto automatically handles international formatting:

```php
// All of these work automatically
$phones = [
    '+1-555-123-4567',     // US format
    '555.123.4567',        // Local format (uses config region)
    '+44 20 7123 4567',    // UK format
    '0912345678',          // Indian format
];

foreach ($phones as $phone) {
    Texto::send($phone, 'International hello!');
}
```

---

## 7. Queueing & Async Flow

1. In queue mode, `Texto::send()` stores a queued row (status `queued`).
2. Dispatches `SendMessageJob` with deterministic primary key.
3. Job invokes `Texto::send(... ['queued_job'=>true,'queued_message_id'=>X])` to perform real API send.
4. Repository upgrades the exact queued record (no racey pattern matching).
5. Status webhooks or polling complete remaining transitions.

Benefits: immediate API responses, backpressure via Laravel queue, deterministic DB state.

---

## 8. Events & Observability

| Event                  | Fired When                                    | Payload                            |
| ---------------------- | --------------------------------------------- | ---------------------------------- |
| `MessageSent`          | Successful provider send                      | `SentMessageResult`                |
| `MessageFailed`        | Send attempt threw `TextoSendFailedException` | `SentMessageResult`, error message |
| `MessageReceived`      | Inbound webhook parsed                        | `WebhookProcessingResult`          |
| `MessageStatusUpdated` | Stored message status mutated (webhook)       | `WebhookProcessingResult`          |

Subscribe in `EventServiceProvider` or use listeners/jobs for analytics, billing, triggers.

Structured logging is emitted at `info` / `debug` levels for sends, polling promotions, template initialization, and failures.

---

## 9. Data Model & Persistence

Table: `texto_messages`

| Column                                    | Notes                                                                                        |
| ----------------------------------------- | -------------------------------------------------------------------------------------------- |
| direction                                 | `sent` / `received`                                                                          |
| driver                                    | `twilio` / `telnyx`                                                                          |
| from_number / to_number                   | E.164 formatted                                                                              |
| body                                      | Nullable for pure media inbound                                                              |
| media_urls                                | JSON array                                                                                   |
| status                                    | Normalized enum (queued, sending, sent, delivered, failed, undelivered, received, ambiguous) |
| provider_message_id                       | SID / Telnyx ID (nullable until known)                                                       |
| error_code                                | Provider error (if any)                                                                      |
| segments_count                            | (Telnyx) part count                                                                          |
| cost_estimate                             | (Telnyx) estimated cost                                                                      |
| metadata                                  | Arbitrary JSON (includes polling counters, conversation info)                                |
| sent_at / received_at / status_updated_at | Timestamps                                                                                   |

`Ambiguous` terminal state occurs when polling exhausts attempts without a provider id or final disposition.

---

## 10. Webhooks

Auto‑registered routes (POST):

| Purpose | Twilio                                  | Telnyx                         |
| ------- | --------------------------------------- | ------------------------------ |
| Inbound | `/texto/webhook/twilio`                 | `/texto/webhook/telnyx`        |
| Status  | (status combined in inbound for Twilio) | `/texto/webhook/telnyx/status` |

Twilio combines inbound + status; for status callbacks it sends `MessageStatus` / `MessageSid` which Texto detects first.

Each request passes through:

1. `VerifyTextoWebhookSecret` – matches `X-Texto-Secret` (if configured).
2. `RateLimitTextoWebhook` – per‑minute throttle (`webhook.rate_limit`).

Inbound payloads are normalized into `WebhookProcessingResult` then persisted via `EloquentMessageRepository`.

---

## 11. Security

| Mechanism            | Description                                                                                 |
| -------------------- | ------------------------------------------------------------------------------------------- |
| Twilio Signature     | Validated via `RequestValidator` unless `TEXTO_TESTING_SKIP_WEBHOOK_VALIDATION` in testing. |
| Telnyx Signature     | Placeholder (extend handler to add HMAC verification).                                      |
| Shared Secret Header | Add `TEXTO_WEBHOOK_SECRET` and send header `X-Texto-Secret`.                                |
| Rate Limiting        | Middleware prevents abuse of webhook endpoints.                                             |
| Phone Parsing        | All numbers canonicalized using libphonenumber.                                             |

---

## 12. Status Polling (Fallback)

Some production networks delay webhooks or they can be transiently disabled. Polling covers that gap.

Enable via `TEXTO_STATUS_POLL_ENABLED=true`. The service provider auto‑schedules `StatusPollJob` each minute. Logic:

-   Select messages in transient states (`queued|sending|sent`) older than `min_age_seconds`.
-   Skip if attempts exceed caps (`max_attempts`, or `queued_max_attempts` for still‑queued w/out provider id).
-   Enforce backoff between polls via `last_poll_at` metadata.
-   Promote forward‑only (e.g., queued -> sent) while avoiding regressions.
-   Mark terminal on delivered/failed/undelivered. Mark `ambiguous` when provider id missing after exhaustion.

Metadata counters (`poll_attempts`, `last_poll_at`, flags) are merged into `metadata` JSON for auditability.

---

## 13. Retry & Backoff

`Retry::exponential()` wraps critical provider API calls (send operations). Configured by `retry.max_attempts` & `retry.backoff_start_ms`. Delay doubles each attempt until max attempts reached. Exceptions escalate as `TextoSendFailedException` leading to `MessageFailed` event emission and (optionally) DB record with status `failed`.

---

## 14. Twilio Conversations & Content Templates

When `TWILIO_USE_CONVERSATIONS=true`, Texto:

1. Lazily initializes Conversations sub‑client.
2. Ensures (or creates) SMS / MMS Content Templates (friendly names configurable).
3. Creates (or reuses) a Conversation per send (deduplicates participant collisions & reuses existing).
4. Optionally attaches per‑conversation webhook (config `conversation_webhook_url` or metadata override).
5. Sends message using template variables (splitting long body into up to 5 × 100‑char chunks). Falls back to body variant if template fails.

Captured metadata includes: `conversation_sid`, `conversation_reused`, optional `conversation_webhook_sid`.

Disable by setting `TWILIO_USE_CONVERSATIONS=false` to revert to classic Messages API.

---

## 15. Extending / Custom Drivers

```php
use Awaisjameel\Texto\Contracts\DriverManagerInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\ValueObjects\{PhoneNumber, SentMessageResult};
use Awaisjameel\Texto\Enums\{Driver, Direction, MessageStatus};

app(DriverManagerInterface::class)->extend('custom', function () {
    return new class implements MessageSenderInterface {
        public function send(PhoneNumber $to, string $body, ?PhoneNumber $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult {
            // ...call provider API...
            return new SentMessageResult(
                Driver::Twilio, // or introduce a new driver enum in a fork
                Direction::Sent,
                $to,
                $from,
                $body,
                $mediaUrls,
                $metadata,
                MessageStatus::Sent,
                'custom-123'
            );
        }
    };
});
```

Driver requirements:

-   Implement `MessageSenderInterface::send()` returning `SentMessageResult`.
-   Optionally expose `fetchStatus()` for polling compatibility.
-   Throw `TextoSendFailedException` for terminal send failures.

---

## API Reference

### Texto Facade

The main entry point for all messaging operations.

#### `Texto::send(string $to, string $body, array $options = []): SentMessageResult`

Send an SMS or MMS message.

**Parameters:**

-   `$to` (string): Recipient phone number (E.164 format or local format)
-   `$body` (string): Message text content
-   `$options` (array): Optional configuration

**Options:**

-   `media_urls` (array): Array of media URLs for MMS
-   `from` (string): Override sender number
-   `driver` (string): Override provider ('twilio' or 'telnyx')
-   `metadata` (array): Custom metadata to store with message

**Returns:** `SentMessageResult` object

**Example:**

```php
$result = Texto::send('+15551234567', 'Hello!', [
    'media_urls' => ['https://example.com/image.jpg'],
    'metadata' => ['campaign' => 'welcome']
]);
```

### Value Objects

#### PhoneNumber

Represents a validated, E.164 formatted phone number.

```php
class PhoneNumber
{
    public readonly string $e164;

    public static function fromString(string $raw, ?string $region = null): self
}
```

**Methods:**

-   `fromString(string $raw, ?string $region = null)`: Parse and validate phone number
-   `__toString()`: Returns E.164 formatted number

#### SentMessageResult

Immutable result object returned after sending a message.

```php
final class SentMessageResult implements Responsable, JsonSerializable
{
    public readonly Driver $driver;
    public readonly Direction $direction;
    public readonly PhoneNumber $to;
    public readonly ?PhoneNumber $from;
    public readonly string $body;
    public readonly array $mediaUrls;
    public readonly array $metadata;
    public readonly MessageStatus $status;
    public readonly ?string $providerMessageId;
    public readonly ?string $errorCode;

    public function toArray(): array
    public function jsonSerialize(): array
    public function toResponse($request): JsonResponse
}
```

#### WebhookProcessingResult

Result object for webhook processing.

```php
final class WebhookProcessingResult
{
    public readonly Driver $driver;
    public readonly Direction $direction;
    public readonly ?PhoneNumber $from;
    public readonly ?PhoneNumber $to;
    public readonly ?string $body;
    public readonly array $mediaUrls;
    public readonly array $metadata;
    public readonly ?string $providerMessageId;
    public readonly ?MessageStatus $status;

    public static function inbound(Driver $driver, PhoneNumber $from, PhoneNumber $to, ?string $body, array $media, array $metadata, ?string $providerMessageId = null): self
    public static function status(Driver $driver, ?string $providerMessageId, MessageStatus $status, array $metadata = []): self
}
```

### Enums

#### MessageStatus

Normalized message status values.

```php
enum MessageStatus: string
{
    case Queued = 'queued';      // Message queued for sending
    case Sending = 'sending';    // Message being sent
    case Sent = 'sent';          // Message sent successfully
    case Delivered = 'delivered'; // Message delivered to recipient
    case Received = 'received';  // Inbound message received
    case Failed = 'failed';      // Send failed permanently
    case Undelivered = 'undelivered'; // Message undelivered
    case Ambiguous = 'ambiguous'; // Status unknown after polling
}
```

#### Driver

Available messaging providers.

```php
enum Driver: string
{
    case Twilio = 'twilio';
    case Telnyx = 'telnyx';
}
```

#### Direction

Message direction.

```php
enum Direction: string
{
    case Sent = 'sent';
    case Received = 'received';
}
```

### Events

#### MessageSent

Fired when a message is successfully sent.

```php
class MessageSent
{
    public function __construct(public readonly SentMessageResult $result) {}
}
```

#### MessageReceived

Fired when an inbound message is received via webhook.

```php
class MessageReceived
{
    public function __construct(public readonly WebhookProcessingResult $result) {}
}
```

#### MessageStatusUpdated

Fired when a message status is updated via webhook or polling.

```php
class MessageStatusUpdated
{
    public function __construct(public readonly WebhookProcessingResult $result) {}
}
```

#### MessageFailed

Fired when a message send attempt fails.

```php
class MessageFailed
{
    public function __construct(
        public readonly SentMessageResult $result,
        public readonly ?string $reason = null
    ) {}
}
```

### Exceptions

#### TextoException

Base exception for all Texto-related errors.

#### TextoSendFailedException

Thrown when message sending fails.

#### TextoWebhookValidationException

Thrown when webhook validation fails.

### Interfaces

#### MessageSenderInterface

Contract for message sending implementations.

```php
interface MessageSenderInterface
{
    public function send(PhoneNumber $to, string $body, ?PhoneNumber $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult;
}
```

#### MessageRepositoryInterface

Contract for message persistence.

```php
interface MessageRepositoryInterface
{
    public function storeSent(SentMessageResult $result): Model;
    public function storeInbound(WebhookProcessingResult $result): Model;
    public function storeStatus(WebhookProcessingResult $result): ?Model;
    public function updatePolledStatus(Message $message, MessageStatus $status, array $extraMetadata = []): Message;
    public function upgradeQueued(int $id, SentMessageResult $result): ?Model;
}
```

#### DriverManagerInterface

Contract for driver management.

```php
interface DriverManagerInterface
{
    public function sender(?Driver $driver = null): MessageSenderInterface;
    public function extend(string $name, callable $factory): void;
}
```

### Console Commands

#### `php artisan texto:install`

Install and configure Texto.

#### `php artisan texto:test-send {to} {body?} {--driver=}`

Send a test message.

**Parameters:**

-   `to`: Recipient phone number
-   `body`: Message body (default: "Test message")
-   `--driver`: Override provider driver

---

## 17. Console Commands

| Command                        | Description                                        |
| ------------------------------ | -------------------------------------------------- |
| `texto:install`                | Publish config + migration then run migrate.       |
| `texto:test-send {to} {body?}` | Fire a manual test message (optional `--driver=`). |
| `texto`                        | Placeholder sample command.                        |

---

## 18. Testing, Fakes & Local Development

-   Uses Pest & Orchestra Testbench for package isolation.
-   Static analysis via PHPStan (`composer analyse`).
-   Code style via Pint (`composer format`).
-   Swap drivers with a fake:

```php
app(\Awaisjameel\Texto\Contracts\DriverManagerInterface::class)
    ->extend('twilio', fn () => new \Awaisjameel\Texto\Drivers\FakeSender());
```

-   Skip webhook signature validation during tests: set `TEXTO_TESTING_SKIP_WEBHOOK_VALIDATION=true`.

Run full suite:

```bash
composer test
```

---

## 19. Architecture Overview

| Layer                                                                           | Responsibility                                                    |
| ------------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| `Texto` facade/root                                                             | Orchestrates send workflow, queue placeholder creation, events.   |
| `DriverManager`                                                                 | Resolves concrete sender implementation (built‑ins + extensions). |
| Drivers (`TwilioSender`, `TelnyxSender`)                                        | Provider API invocation + provider‑specific metadata enrichment.  |
| `StatusMapper`                                                                  | Converts raw provider statuses / events to internal enum.         |
| `EloquentMessageRepository`                                                     | Persistence & deterministic queued upgrade + polling updates.     |
| Jobs (`SendMessageJob`, `StatusPollJob`)                                        | Async send & periodic status reconciliation.                      |
| Webhook Handlers                                                                | Parse & validate inbound/status payloads per provider.            |
| Support Utilities (`Retry`, `PollingParameterResolver`, `TwilioContentService`) | Cross‑cutting helpers.                                            |
| Value Objects / Enums                                                           | Strongly typed domain primitives.                                 |

Design goals: minimal public API surface (`Texto::send`), encapsulated provider variance, explicit lifecycle events, observability via logs + metadata.

---

## 20. Troubleshooting & FAQ

### Common Issues

**Q: Messages stuck in `queued` status**
A: This usually indicates queue processing issues.

-   Verify `TEXTO_QUEUE=true` in your environment
-   Ensure a queue worker is running: `php artisan queue:work`
-   Check queue connection configuration
-   Review Laravel logs for job processing errors
-   Enable status polling as fallback: `TEXTO_STATUS_POLL_ENABLED=true`

**Q: Webhook signature validation fails (401 errors)**
A: Signature validation ensures webhook authenticity.

-   For Twilio: Verify `TWILIO_AUTH_TOKEN` matches your Twilio console
-   Ensure webhook URLs in provider console exactly match your routes (including protocol)
-   For local development, use ngrok or similar tunneling service
-   Check that webhook URLs don't have trailing slashes or query parameters

**Q: Twilio Conversations template creation warnings**
A: Template auto-provisioning may fail due to permissions.

-   This is non-fatal; Texto falls back to direct message sending
-   Check Twilio account has Content API permissions
-   Verify `TWILIO_ACCOUNT_SID` and `TWILIO_AUTH_TOKEN` are correct
-   Template creation warnings don't prevent message sending

**Q: Telnyx cost/segment data missing**
A: Cost and segment information is only provided in specific response scenarios.

-   Ensure your Telnyx API key has messaging permissions
-   Cost data appears only when Telnyx includes it in API responses
-   Segment counts depend on message content and provider logic

**Q: Messages failing with provider errors**
A: Check provider account status and configuration.

-   Verify API credentials are correct and active
-   Ensure sender numbers are verified/purchased in provider console
-   Check provider account has sufficient balance/credits
-   Review message content for prohibited terms

**Q: High memory usage with large message volumes**
A: Optimize for high-throughput scenarios.

-   Enable queuing: `TEXTO_QUEUE=true`
-   Use database queue driver for reliability
-   Configure appropriate queue worker settings
-   Monitor queue depth and processing rates

### Status Definitions

**Q: What does `ambiguous` status mean?**
A: Messages reach ambiguous status when polling exhausts all attempts without determining final delivery status.

-   Occurs when provider ID is missing and polling can't retrieve status
-   Investigate upstream provider logs for root cause
-   May indicate provider API issues or message filtering

**Q: Difference between `failed` and `undelivered`?**
A: These represent different failure modes:

-   `failed`: Immediate sending failure (invalid number, blocked content, etc.)
-   `undelivered`: Message sent but delivery failed (phone off, full mailbox, etc.)

### Configuration Issues

**Q: How to disable message persistence?**
A: Set `TEXTO_STORE_MESSAGES=false` in your environment.

-   Events will still fire normally
-   `SentMessageResult` objects are still returned
-   Useful for testing or when external logging is preferred

**Q: Phone number validation too strict**
A: Adjust the default region for number parsing.

-   Set `TEXTO_DEFAULT_REGION` to your primary market (e.g., 'GB' for UK)
-   This affects how local format numbers are interpreted
-   E.164 format (+country code) always works regardless of region

### Provider-Specific Issues

**Q: Twilio rate limiting**
A: Twilio enforces sending limits based on account type.

-   Free accounts: 100 messages/day
-   Trial accounts: Limited sending
-   Full accounts: Higher limits based on verification level
-   Implement queuing and backoff strategies

**Q: Telnyx webhook delays**
A: Telnyx webhooks may have higher latency than Twilio.

-   Enable status polling for critical delivery tracking
-   Configure appropriate polling intervals
-   Monitor webhook delivery logs

### Performance Tuning

**Q: Optimizing for high volume**
A: Several configuration options for performance:

-   Use Redis/database queues instead of sync processing
-   Configure multiple queue workers
-   Enable status polling with appropriate batch sizes
-   Monitor database indexes on `texto_messages` table
-   Consider message archiving for old records

**Q: Database performance with many messages**
A: The `texto_messages` table can grow quickly.

-   Add database indexes on frequently queried columns
-   Implement message archiving/cleanup strategies
-   Consider partitioning for very high volume
-   Monitor query performance and optimize as needed

### Development & Testing

**Q: Testing without sending real messages**
A: Use the fake driver for testing:

```php
app(DriverManagerInterface::class)->extend('twilio', fn() => new FakeSender());
```

-   Skip webhook validation in tests: `TEXTO_TESTING_SKIP_WEBHOOK_VALIDATION=true`
-   Use test credentials or mock HTTP responses

**Q: Local development with webhooks**
A: Webhooks require public URLs for provider access.

-   Use ngrok, localtunnel, or similar services
-   Configure webhook URLs in provider console
-   Consider webhook testing tools like webhook.site for debugging

### Extending Texto

**Q: Adding a new provider (e.g., Vonage)**
A: Implement the extension pattern:

```php
app(DriverManagerInterface::class)->extend('vonage', function() {
    return new class implements MessageSenderInterface {
        public function send(PhoneNumber $to, string $body, ?PhoneNumber $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult {
            // Your implementation
        }
    };
});
```

-   Consider contributing back via PR for official support
-   Follow existing driver patterns for consistency

**Q: Custom webhook handling**
A: Extend webhook handlers for custom logic:

-   Create custom handler class implementing `WebhookHandlerInterface`
-   Register in service provider or route configuration
-   Handle provider-specific webhook formats

---

## 21. Performance Considerations & Best Practices

### Database Optimization

For high-volume applications, optimize the `texto_messages` table:

```sql
-- Add performance indexes
CREATE INDEX idx_texto_messages_status_created ON texto_messages (status, created_at);
CREATE INDEX idx_texto_messages_provider_id ON texto_messages (provider_message_id);
CREATE INDEX idx_texto_messages_from_to ON texto_messages (from_number, to_number);

-- Consider partitioning for very high volume
-- Partition by month for message archiving
ALTER TABLE texto_messages PARTITION BY RANGE (YEAR(created_at)) (
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p2025 VALUES LESS THAN (2026)
);
```

### Queue Configuration

For reliable message processing at scale:

```php
// config/queue.php
'connections' => [
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90, // Increase for messaging jobs
    ],
],
```

Run multiple workers for high throughput:

```bash
# Multiple workers for parallel processing
php artisan queue:work --queue=texto-high,texto-normal --max-jobs=1000 --sleep=3
php artisan queue:work --queue=texto-bulk --max-jobs=500 --sleep=5
```

### Monitoring & Alerting

Implement monitoring for critical messaging operations:

```php
// Monitor queue health
$pendingJobs = DB::table('jobs')->where('queue', 'like', 'texto%')->count();
if ($pendingJobs > 1000) {
    Log::warning('High texto queue backlog', ['count' => $pendingJobs]);
}

// Monitor failure rates
$failureRate = Message::where('status', 'failed')
    ->where('created_at', '>', now()->subHour())
    ->count() / Message::where('created_at', '>', now()->subHour())->count();

if ($failureRate > 0.1) { // 10% failure rate
    // Alert or take action
}
```

### Cost Optimization

Track and optimize messaging costs:

```php
// Analyze costs by provider and campaign
$costs = Message::selectRaw('
        driver,
        SUM(cost_estimate) as total_cost,
        COUNT(*) as message_count,
        AVG(cost_estimate) as avg_cost
    ')
    ->whereNotNull('cost_estimate')
    ->where('created_at', '>', now()->subMonth())
    ->groupBy('driver')
    ->get();

// Implement cost thresholds
if ($costs->sum('total_cost') > 1000) { // Monthly budget
    // Send alert or implement throttling
}
```

### Security Best Practices

Secure your messaging infrastructure:

```php
// Use environment variables for secrets
// Never commit API keys to version control

// Implement rate limiting per user/phone
RateLimiter::for('texto-send', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()->id);
});

// Validate phone numbers strictly
$phone = PhoneNumber::fromString($request->phone, 'US'); // Specify region
if (!$phone) {
    throw new InvalidPhoneNumberException();
}
```

### Error Handling & Resilience

Implement comprehensive error handling:

```php
try {
    $result = Texto::send($phone, $message, $options);
} catch (TextoSendFailedException $e) {
    // Log detailed error
    Log::error('Message send failed', [
        'phone' => $phone,
        'error' => $e->getMessage(),
        'driver' => config('texto.driver')
    ]);

    // Implement fallback logic
    if (config('texto.driver') === 'twilio') {
        // Try Telnyx as fallback
        $result = Texto::send($phone, $message, ['driver' => 'telnyx'] + $options);
    }

    // Notify user or take alternative action
}
```

### Testing Strategies

Comprehensive testing approach:

```php
// Unit tests for drivers
class TwilioSenderTest extends TestCase
{
    public function test_sends_message_successfully()
    {
        // Mock Twilio client
        $this->mock(TwilioClient::class, function ($mock) {
            $mock->shouldReceive('messages->create')
                ->once()
                ->andReturn((object)['sid' => 'SM123']);
        });

        $result = app(TwilioSender::class)->send(
            PhoneNumber::fromString('+15551234567'),
            'Test message'
        );

        $this->assertEquals(MessageStatus::Sent, $result->status);
    }
}

// Integration tests with fake driver
class MessagingIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Use fake driver for integration tests
        app(DriverManagerInterface::class)->extend('twilio', fn() => new FakeSender());
    }
}
```

### Scaling Considerations

For enterprise-level messaging:

1. **Horizontal Scaling**: Deploy across multiple servers with shared queue
2. **Database Sharding**: Split message storage across multiple databases
3. **CDN for Media**: Use CDNs for MMS media to reduce bandwidth
4. **Provider Redundancy**: Implement multi-provider failover logic
5. **Caching**: Cache frequently used phone number validations
6. **Async Processing**: Always use queues for production deployments

### Compliance & Data Protection

Handle sensitive messaging data appropriately:

```php
// Implement data retention policies
Message::where('created_at', '<', now()->subMonths(6))
    ->whereIn('status', ['delivered', 'failed'])
    ->delete();

// Encrypt sensitive metadata
$message->metadata = encrypt(json_encode([
    'ssn' => $sensitiveData, // Encrypted storage
    'campaign' => 'public_data'
]));

// Implement audit logging
Log::channel('messaging-audit')->info('Message sent', [
    'id' => $message->id,
    'to' => $message->to_number, // Log for compliance
    'timestamp' => now(),
    'user_id' => auth()->id()
]);
```

## 22. Roadmap

### Planned Features

-   **Multi-provider Routing**: Intelligent load balancing and failover across providers
-   **Additional Providers**: Official support for MessageBird, Vonage, AWS SNS, and others
-   **Template Engine**: Unified templating system for all providers
-   **Bulk Operations**: Batch sending with progress tracking and error aggregation
-   **Advanced Analytics**: Built-in reporting and analytics dashboard
-   **Webhook Enhancements**: Improved webhook signature verification and replay protection
-   **Rate Limiting**: Provider-aware rate limiting and throttling
-   **Geographic Routing**: Route messages via local providers for cost optimization

### Community Contributions

We welcome contributions! Areas of particular interest:

-   New provider implementations
-   Performance optimizations
-   Enhanced testing utilities
-   Documentation improvements
-   Integration packages for popular frameworks

### Version Compatibility

| Texto Version | Laravel Version | PHP Version | Status  |
| ------------- | --------------- | ----------- | ------- |
| 1.x           | 10.0 - 12.x     | 7.4 - 8.2   | Active  |
| 2.x           | 11.0 - 13.x     | 8.1 - 8.3   | Planned |

### Migration Guide

#### Upgrading from 1.0 to 1.1

No breaking changes. New features:

-   Enhanced status polling with configurable backoff
-   Improved error handling and logging
-   Additional metadata fields for cost tracking

#### Future Breaking Changes (2.0)

Planned improvements that may require migration:

-   Updated configuration structure
-   New required environment variables
-   Changes to event payloads
-   Database schema updates

Monitor release notes for detailed migration instructions.

---

## 23. Contributing

We welcome contributions from the community! Here's how to get involved:

### Development Setup

```bash
# Fork and clone the repository
git clone https://github.com/your-username/texto.git
cd texto

# Install dependencies
composer install

# Copy environment file and configure
cp .env.example .env
# Add your Twilio/Telnyx test credentials

# Run tests
composer test

# Run static analysis
composer analyse

# Format code
composer format
```

### Contribution Guidelines

1. **Open an Issue First**: For significant changes, open a descriptive issue to discuss the proposed changes.
2. **Code Quality**: All contributions must pass quality checks:

    ```bash
    composer analyse  # PHPStan static analysis
    composer format   # Laravel Pint code formatting
    composer test     # Pest test suite
    ```

3. **Testing**: Add tests for new features and bug fixes:

    - Unit tests for classes and methods
    - Integration tests for full workflows
    - Use the `FakeSender` for testing without external APIs

4. **Documentation**: Update documentation for user-visible changes:

    - README.md for new features and usage examples
    - Inline code documentation (PHPDoc)
    - CHANGELOG.md for version history

5. **Type Safety**: Keep new public APIs strongly typed using PHP 7.4+ features.

### Code Style

Follow Laravel's coding standards with Pint configuration:

```php
// Good: Use type hints and return types
public function send(PhoneNumber $to, string $body): SentMessageResult

// Good: Use enums for fixed values
public function __construct(public readonly MessageStatus $status)

// Good: Comprehensive PHPDoc
/**
 * Send an SMS/MMS message using the active driver.
 *
 * @param  string  $to  E.164 formatted recipient number
 * @param  string  $body  Message body text
 * @param  array{media_urls?:string[], metadata?:array}  $options
 */
```

### Testing Strategy

```php
// Unit test example
test('phone number validation', function () {
    $phone = PhoneNumber::fromString('+15551234567');
    expect($phone->e164)->toBe('+15551234567');
});

// Integration test example
test('message sending workflow', function () {
    // Use fake driver to avoid external calls
    app(DriverManagerInterface::class)->extend('twilio', fn() => new FakeSender());

    $result = Texto::send('+15551234567', 'Test message');

    expect($result->status)->toBe(MessageStatus::Sent);
    expect($result->providerMessageId)->toBeString();
});
```

### Pull Request Process

1. **Branch Naming**: Use descriptive branch names:

    - `feature/add-vonage-driver`
    - `fix/webhook-validation-bug`
    - `docs/improve-api-reference`

2. **Commit Messages**: Follow conventional commits:

    - `feat: add Vonage driver support`
    - `fix: resolve webhook signature validation`
    - `docs: update API reference section`

3. **PR Description**: Include:

    - Clear description of changes
    - Screenshots for UI changes (if applicable)
    - Test coverage information
    - Breaking changes (if any)

4. **Review Process**: All PRs require review and must pass CI checks.

### Areas for Contribution

**High Priority:**

-   New provider implementations (MessageBird, Vonage, AWS SNS)
-   Performance optimizations for high-volume sending
-   Enhanced webhook security features

**Medium Priority:**

-   Additional testing utilities and helpers
-   Documentation improvements and translations
-   Integration packages for popular Laravel packages

**Good for Beginners:**

-   Bug fixes and small improvements
-   Additional code examples and tutorials
-   Test coverage improvements

### Community Support

-   **Discussions**: Use GitHub Discussions for questions and ideas
-   **Issues**: Report bugs and request features via GitHub Issues
-   **Discord/Slack**: Join our community chat for real-time help

### Recognition

Contributors are recognized in:

-   CHANGELOG.md for significant contributions
-   GitHub's contributor insights
-   Social media mentions for major features

Thank you for contributing to Texto! 🎉

---

## 23. Security Policy

Report vulnerabilities privately via GitHub Security Advisories. Do not disclose publicly until patched. Avoid sharing live credentials or full raw webhook payloads containing PII in issues.

---

## 24. Migration Guide

### Upgrading Versions

#### From 1.0.x to 1.1.x

No breaking changes. New features include:

-   Enhanced status polling with configurable backoff strategies
-   Improved error handling and structured logging
-   Additional metadata fields for cost tracking
-   Better webhook validation and security

**Migration Steps:**

1. Update package: `composer update awaisjameel/texto`
2. Review new configuration options in `config/texto.php`
3. Update environment variables if needed
4. Test webhook endpoints with new validation

#### From 0.x to 1.x

Breaking changes in the 1.0 release:

**Configuration Changes:**

```php
// Old (0.x)
'driver' => env('TEXTO_DRIVER', 'twilio'),

// New (1.x) - same, but additional options available
'driver' => env('TEXTO_DRIVER', 'twilio'),
'store_messages' => env('TEXTO_STORE_MESSAGES', true),
'queue' => env('TEXTO_QUEUE', false),
```

**API Changes:**

```php
// Old (0.x)
Texto::send('+15551234567', 'Hello');

// New (1.x) - same API, enhanced return type
$result = Texto::send('+15551234567', 'Hello');
$result->status; // Now returns MessageStatus enum
```

**Migration Steps:**

1. Backup your database
2. Update to 1.x: `composer update awaisjameel/texto`
3. Run `php artisan texto:install` to update config and migrations
4. Update any code using status strings to use `MessageStatus` enums
5. Test thoroughly in staging environment

### Environment Variable Changes

| Old Variable | New Variable                | Notes                          |
| ------------ | --------------------------- | ------------------------------ |
| -            | `TEXTO_STORE_MESSAGES`      | Control message persistence    |
| -            | `TEXTO_QUEUE`               | Enable async processing        |
| -            | `TEXTO_WEBHOOK_SECRET`      | Shared secret for webhook auth |
| -            | `TEXTO_STATUS_POLL_ENABLED` | Enable status polling fallback |

### Database Schema Changes

Version 1.x adds new columns to `texto_messages`:

```sql
-- New columns in 1.x
ALTER TABLE texto_messages ADD COLUMN segments_count INT NULL;
ALTER TABLE texto_messages ADD COLUMN cost_estimate DECIMAL(10,4) NULL;
ALTER TABLE texto_messages ADD COLUMN status_updated_at TIMESTAMP NULL;
```

These are nullable and backward compatible.

### Webhook URL Changes

Webhook routes remain the same but include enhanced validation:

-   `/texto/webhook/twilio` - Twilio webhooks (SMS + status)
-   `/texto/webhook/telnyx` - Telnyx inbound messages
-   `/texto/webhook/telnyx/status` - Telnyx status updates

Ensure your provider console webhook URLs match exactly.

### Testing Changes

Update your tests to use the new `FakeSender`:

```php
// Old approach
// Custom mock setup

// New approach
app(DriverManagerInterface::class)->extend('twilio', fn() => new FakeSender());
```

## 25. License & Credits

### License

Released under the MIT License. See [LICENSE.md](LICENSE.md) for details.

### Credits

**Created by:** [awaisjameel](https://github.com/awaisjameel)

**Inspiration & Thanks:**

-   [Spatie Laravel Package Tools](https://github.com/spatie/laravel-package-tools) - Package skeleton
-   Laravel OSS Ecosystem - Best practices and patterns
-   Twilio & Telnyx Developer Communities - API insights

### Contributors

We'd like to thank all contributors who have helped make Texto better:

-   [awaisjameel](https://github.com/awaisjameel)

### Sponsors

Support Texto's development:

[![GitHub Sponsors](https://img.shields.io/github/sponsors/awaisjameel)](https://github.com/sponsors/awaisjameel)

### Related Projects

-   [Laravel Notification Channels](https://github.com/laravel-notification-channels) - Alternative notification approach
-   [Twilio PHP SDK](https://github.com/twilio/twilio-php) - Official Twilio library
-   [Telnyx PHP SDK](https://github.com/telnyx/telnyx-php) - Official Telnyx library

---

_Made with ❤️ for the Laravel community_

---

### Quick Reference Cheat Sheet

```php
// Basic SMS
$result = Texto::send('+15551234567', 'Hello World!');

// MMS with media
$result = Texto::send('+15551234567', 'Check this out!', [
    'media_urls' => ['https://example.com/image.jpg']
]);

// Override provider per message
$result = Texto::send('+15551234567', 'Via Telnyx', [
    'driver' => 'telnyx'
]);

// Custom sender and metadata
$result = Texto::send('+15551234567', 'Promotional message', [
    'from' => '+15550009999',
    'metadata' => ['campaign' => 'spring_sale', 'priority' => 'high']
]);

// Async processing (when TEXTO_QUEUE=true)
$result = Texto::send('+15551234567', 'Queued message');
// $result->status === MessageStatus::Queued

// Event listeners
Event::listen(MessageSent::class, function ($event) {
    Log::info('Message sent', ['id' => $event->result->providerMessageId]);
});
```

---

## Support & Community

-   📖 **Documentation**: You're reading it! Check the [GitHub repository](https://github.com/awaisjameel/texto) for the latest updates
-   🐛 **Bug Reports**: [Open an issue](https://github.com/awaisjameel/texto/issues) on GitHub
-   💡 **Feature Requests**: [Start a discussion](https://github.com/awaisjameel/texto/discussions) on GitHub
-   💬 **Community Chat**: Join our [Discord server](https://discord.gg/texto) for real-time help
-   ⭐ **Show Support**: Star the repo if Texto saves you time and effort!

---

_Built with ❤️ for the Laravel community by [awaisjameel](https://github.com/awaisjameel)_
