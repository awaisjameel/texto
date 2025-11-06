<div align="center">

# Texto

`<strong>`Unified, extensible Laravel gateway for sending & receiving SMS/MMS over Twilio & Telnyx.`<br/>`Batteries included: queueing, retries, events, webhooks, polling, typed value objects.`</strong>`

[![Latest Version on Packagist](https://img.shields.io/packagist/v/awaisjameel/texto.svg?style=flat-square)](https://packagist.org/packages/awaisjameel/texto)
[![Tests](https://img.shields.io/github/actions/workflow/status/awaisjameel/texto/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/awaisjameel/texto/actions?query=workflow%3Atests+branch%3Amain)
[![Downloads](https://img.shields.io/packagist/dt/awaisjameel/texto.svg?style=flat-square)](https://packagist.org/packages/awaisjameel/texto)
[![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE.md)

</div>

Texto gives you a cohesive abstraction for carrier‑grade messaging in Laravel 10–12 (PHP 7.4 / 8.2+). It normalizes provider differences (Twilio, Telnyx) behind a small set of contracts and value objects, persists sent & inbound messages, securely processes delivery status notifications, and offers a rich event surface for further automation. Advanced niceties include Twilio Conversations + Content Template auto‑provisioning, exponential retry, opt‑in status polling (for when webhooks lag), and a plug‑and‑play driver extension API.

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

Messaging APIs differ subtly (parameters, status taxonomies, webhook formats, content templating). Ad‑hoc conditionals quickly devolve into brittle code. Texto centralizes those concerns:

-   Strong typing (enums + value objects) to surface intent & reduce mistakes.
-   Separation of concerns: drivers focus on provider logic; repository persists; mapper normalizes status.
-   Extensibility first: `DriverManager::extend()` lets you bolt in new providers cleanly.
-   Observability: comprehensive events, metadata capture, structured logging.
-   Graceful degradation: queueing, retries, fallback polling if webhooks are late / disabled.
-   Security posture: signature validation + shared secret + rate limiting middleware.

---

## 2. Feature Overview

-   SMS + MMS send (Twilio & Telnyx drivers)
-   Inbound message capture & storage
-   Delivery status tracking via webhooks + optional polling
-   Queue based async sends (returns immediate queued placeholder)
-   Exponential retry wrapper for transient upstream failures
-   Twilio Conversations + Content template flow (auto create / reuse templates)
-   Telnyx rich metadata (cost, parts) captured into message metadata
-   Structured events: `MessageSent`, `MessageReceived`, `MessageFailed`, `MessageStatusUpdated`
-   Status normalization layer (`StatusMapper`)
-   Strong domain primitives (`PhoneNumber`, `SentMessageResult`)
-   Extensible driver registration at runtime
-   Secure webhook processing (Twilio signature; Telnyx placeholder + shared secret header)
-   Rate limiting middleware + optional shared secret header `X-Texto-Secret`
-   Status polling job with intelligent promotion rules & ambiguity handling
-   Deterministic upgrade of queued DB rows when async job completes
-   CI ready (Pest + PHPStan + Pint)

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

Manual publish steps if you prefer granular control:

```bash
composer require awaisjameel/texto
php artisan vendor:publish --tag=texto-config
php artisan vendor:publish --tag=texto-migrations
php artisan migrate
```

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

## 5. Configuration Highlights (`config/texto.php`)

| Key                                             | Purpose                                         |
| ----------------------------------------------- | ----------------------------------------------- |
| `driver`                                        | Active provider driver (enum `Driver::Twilio    |
| `store_messages`                                | Toggle DB persistence (table `texto_messages`). |
| `queue`                                         | Enable async dispatch via `SendMessageJob`.     |
| `retry.max_attempts` / `retry.backoff_start_ms` | Exponential retry parameters.                   |
| `webhook.secret`                                | Shared secret header (`X-Texto-Secret`).        |
| `webhook.rate_limit`                            | Per‑minute throttle for webhook routes.         |
| `validation.region`                             | Default region for parsing raw numbers.         |
| `status_polling.*`                              | Polling strategy & limits.                      |
| `twilio.*`                                      | Conversations + content template settings.      |
| `telnyx.*`                                      | API key, profile id, default from.              |

---

## 6. Usage Examples

### Basic Send

```php
Texto::send('+15551234567', 'Hello world');
```

### With Media (MMS)

```php
Texto::send('+15551234567', 'Check this out', [
    'media_urls' => ['https://example.com/image.jpg']
]);
```

### Override Driver Per Message

```php
Texto::send('+15551234567', 'Via Telnyx now', ['driver' => 'telnyx']);
```

### Custom From Number / Metadata

```php
Texto::send('+15551234567', 'Branded', [
    'from' => '+15550009999',
    'metadata' => ['campaign' => 'spring_launch']
]);
```

### Queued Mode

Enable `TEXTO_QUEUE=true` then:

```php
$result = Texto::send('+15551234567', 'Async hello');
// $result->status === MessageStatus::Queued
```

Run a worker: `php artisan queue:work`.

### Returned Value Object

`SentMessageResult` implements `Responsable` + `JsonSerializable` so you can directly `return Texto::send(...);` from a controller.

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

## 16. Value Objects & Enums

| Type                 | Purpose                                                                                         |
| -------------------- | ----------------------------------------------------------------------------------------------- |
| `PhoneNumber`        | Canonical E.164 representation; validation via libphonenumber.                                  |
| `SentMessageResult`  | Immutable result describing send attempt; Responsable + JSON ready.                             |
| `MessageStatus` enum | Normalized states (queued, sending, sent, delivered, failed, undelivered, received, ambiguous). |
| `Driver` enum        | Provider selection.                                                                             |
| `Direction` enum     | `sent` or `received`.                                                                           |

These construct the domain language and reduce ad‑hoc string comparisons.

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

**Q: Messages remain in `queued` status.**
A: Ensure a queue worker is running and `TEXTO_QUEUE=true`. Check logs for send failures; verify credentials. Polling can promote status if enabled.

**Q: Conversations template creation warnings.**
A: Not fatal. Texto falls back to body send if template provisioning fails. Check Twilio Content API permissions.

**Q: Telnyx cost/parts missing.**
A: They appear only when returned by Telnyx response; ensure API key has proper messaging permissions.

**Q: Webhook 401 / signature errors.**
A: Confirm Twilio auth token matches, and the public URL matches Twilio console config exactly (including protocol). For local dev use ngrok & update Twilio config.

**Q: Need another provider (e.g., Vonage).**
A: Implement a new driver via `extend()` and (optionally) PR the enum + sender.

**Q: What is `ambiguous` status?**
A: Polling gave up promoting a message lacking a provider id or final result—investigate upstream logs.

**Q: Disable persistence?**
A: Set `TEXTO_STORE_MESSAGES=false`; events still fire; value objects returned.

---

## 21. Roadmap

-   Multi‑driver weighted routing & failover
-   Additional providers (MessageBird, Vonage, etc.)
-   Template/content abstraction for non‑Twilio providers
-   Bulk send batching helpers
-   Enhanced Telnyx signature verification
-   Rate limit / throttling strategies per driver

---

## 22. Contributing

PRs welcome! Please:

1. Open a descriptive issue (optional but helpful).
2. Run: `composer analyse && composer format && composer test`.
3. Add tests & update README + CHANGELOG for user‑visible changes.
4. Keep new public APIs strongly typed.

---

## 23. Security Policy

Report vulnerabilities privately via GitHub Security Advisories. Do not disclose publicly until patched. Avoid sharing live credentials or full raw webhook payloads containing PII in issues.

---

## 24. License & Credits

Released under the MIT License (see `LICENSE.md`).
Crafted by [awaisjameel](https://github.com/awaisjameel) with inspiration from the Spatie Laravel package skeleton and the broader Laravel OSS ecosystem.

---

### At a Glance Cheat‑Sheet

```php
// Basic
Texto::send('+15551234567', 'Ping');

// With media
Texto::send('+15551234567', 'Photo', ['media_urls' => ['https://example.com/pic.jpg']]);

// Per‑message driver override
Texto::send('+15551234567', 'Hi via Telnyx', ['driver' => 'telnyx']);

// Custom from + metadata
Texto::send('+15551234567', 'Promo', [
    'from' => '+15550009999',
    'metadata' => ['campaign' => 'spring']
]);
```

---

Enjoy building with Texto. Star the repo if it saves you time! ⭐
