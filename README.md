# Texto

A Laravel messaging gateway for Twilio and Telnyx SMS/MMS, Meta WhatsApp Cloud API, and the application's Laravel mailer. Typed addresses and results share one persistence, queue, webhook, and event flow.

Version 4 requires PHP 8.2-8.5 and Laravel 12 or 13. Laravel 13 requires PHP 8.3+. Enable Sodium for Telnyx webhook verification. Read [MIGRATION.md](MIGRATION.md) before upgrading.

## Install

```sh
composer require awaisjameel/texto:^4.0
php artisan texto:install
```

The installer publishes configuration and both migrations, then runs migrations. Existing published files are preserved; use `--force` only when deliberately replacing them. In a controlled deployment, publish `texto-config` and `texto-migrations` separately, review configuration, and run `php artisan migrate --force` before starting workers. Laravel discovers the provider and `Texto` alias automatically.

Configure `APP_KEY` before sending or queueing messages. Credential snapshots and queue payloads use Laravel encryption. Keep the key available to workers and use Laravel's previous-key support during key rotation.

## Send messages

```php
use Awaisjameel\Texto\Facades\Texto;
use Awaisjameel\Texto\Enums\MessageStatus;

$result = Texto::send('+14155552671', 'Your order is ready.', [
    'driver' => 'telnyx',
    'metadata' => ['order_id' => 123],
]);

if ($result->status === MessageStatus::Failed) {
    // Handle the failure; a rejected send returns a failed result and emits MessageFailed.
}
```

Set `TEXTO_DRIVER` to `twilio`, `telnyx`, `whatsapp`, or `email`. The recipient does not select the driver automatically. Phone drivers use E.164-normalized `PhoneNumber` values and reject email addresses; the email driver accepts `EmailAddress`, including `"Doe, Jane" <jane@example.com>`.

Common options are `driver`, `from`, `media_urls` (a list of URLs), and `metadata`. A `driver_config` array overrides that driver's configuration for this send without changing global configuration or another sender instance. The effective configuration, including overrides, is encrypted with the stored outbound message so polling uses the original account.

`SentMessageResult` exposes `driver`, `direction`, `to`, `from`, `body`, `mediaUrls`, `metadata`, `status`, `providerMessageId`, and `errorCode`. Use `$result->to->value()` for either address family. Provider acceptance is `Sent`, not proof of final delivery. Invalid configuration/address input can throw before the send; transport rejections are reported as failed results. Treat timeouts and unknown remote outcomes as potentially accepted before manually resending.

`sendDirect()` takes the same arguments and bypasses queue mode.

## Providers

| Driver | Required configuration | Delivery tracking |
| --- | --- | --- |
| Twilio | `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_NUMBER` | Signed callbacks and optional polling |
| Telnyx | `TELNYX_API_KEY`, `TELNYX_MESSAGING_PROFILE_ID`, `TELNYX_FROM_NUMBER`; `TELNYX_PUBLIC_KEY` for webhooks | Signed callbacks and optional polling |
| WhatsApp | `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`; `WHATSAPP_APP_SECRET` and `WHATSAPP_VERIFY_TOKEN` for webhooks | Signed batch callbacks |
| Email | Existing Laravel mail configuration and a sender address | Normalized authenticated application/provider callbacks |

See the focused guides for [Twilio](docs/twilio.md), [Telnyx](docs/telnyx.md), and [WhatsApp](docs/whatsapp.md). Provider registration, messaging permissions, templates, service windows, media limits and pricing remain provider/account requirements; Texto does not bypass them. HTTP adapters validate response objects and required message IDs, paginate supported list operations, and do not follow redirects with credentials.

### Email

```php
Texto::send('Jane <jane@example.com>', 'Plain text body', [
    'driver' => 'email',
    'from' => 'Support <support@example.com>',
    'subject' => 'Order update',
    'html' => '<p>Your order is ready.</p>',
    'cc' => ['team@example.com'],
    'reply_to' => 'help@example.com',
    'attachments' => [storage_path('app/receipt.pdf')],
]);
```

`TEXTO_EMAIL_MAILER` selects an existing named mailer; otherwise Laravel's default is used. Sender defaults are `TEXTO_EMAIL_FROM_ADDRESS` / `TEXTO_EMAIL_FROM_NAME`, then `mail.from`. `TEXTO_EMAIL_DEFAULT_SUBJECT` defaults to `New message`.

Attachments accept a local path or an array with `path` or `url`, plus optional `name` and `mime`. `media_urls` also become attachments. Only supply trusted attachment paths/URLs: remote attachments are fetched by the application. Text and HTML views are shipped and can be published with `texto-views`. A mailer cancelled by `MessageSending` is a failed send. SMTP acceptance is tracked using the RFC Message-ID; a provider-specific transformer must correlate its callbacks with that ID.

## Queues, persistence and events

Set `TEXTO_QUEUE=true` and run Laravel workers. Each send creates a `Queued` ledger row, dispatches an encrypted `SendMessageJob`, and upgrades the same row after execution. The job carries the original effective configuration and named addresses. It uses a per-row overlap lock and skips an already accepted/terminal row on redelivery. `store_messages=false` disables this ledger-based deduplication.

Send jobs have one queue attempt to avoid automatically repeating a non-idempotent send whose remote outcome is unknown. Provider rate-limit retry remains bounded by `TEXTO_RETRY_ATTEMPTS` (default 3) and `TEXTO_RETRY_BACKOFF_START` milliseconds (default 200). Unexpected job failures mark a still-pending row `Ambiguous`, which requires reconciliation rather than automatic resend. Queue systems cannot provide exactly-once external delivery across a crash after provider acceptance and before database persistence.

Use a shared cache for locks across workers. Worker timeouts and queue `retry_after` must be coordinated; restart workers after deployment. Encrypted jobs require the same application encryption key as the producer.

`texto_messages` stores direction, driver, addresses, provider ID, body/media, metadata, status and poll counters. The new nullable TEXT `driver_config` column uses Laravel's `encrypted:array` cast and is hidden from model array/JSON output. Protect database backups and `APP_KEY`; encryption does not replace access control. Old rows without snapshots use current configuration when polled. `cost_estimate` is a decimal string and `segments_count` is an integer.

Inbound deduplication is scoped by driver, direction and provider ID. Custom inbound results without a provider ID cannot be deduplicated. Status callbacks correlate existing outbound rows; they do not invent a row for an unknown provider ID. Row locks and forward-only status progression prevent stale polling from overwriting newer webhook states. SQLite is covered by the package tests; applications should also validate their production database configuration.

Events contain typed results:

- `MessageSent` after a successful send has been persisted.
- `MessageFailed` after the failed result has been persisted.
- `MessageReceived` for a newly stored inbound delivery.
- `MessageStatusUpdated` when a stored outbound status advances.

With storage disabled, authentic inbound/status callbacks emit their result events without database access, and applications own deduplication.

## Webhooks

Routes are registered without the `web` CSRF middleware. Configure the provider's **POST** destination:

| Endpoint | Authentication |
| --- | --- |
| `/texto/webhook/twilio` | `X-Twilio-Signature`; URL/form HMAC-SHA1 or JSON `bodySHA256` plus URL signature |
| `/texto/webhook/telnyx` | `telnyx-timestamp` and `telnyx-signature-ed25519`; Ed25519 over `timestamp|raw-body` |
| `/texto/webhook/whatsapp` | `X-Hub-Signature-256`; raw-body HMAC-SHA256 using the Meta app secret |
| `/texto/webhook/email` | `X-Texto-Secret` matching `TEXTO_WEBHOOK_SECRET`; legacy query `secret` also supported |

WhatsApp also exposes a GET subscription handshake with the configured verify token. Twilio/Telnyx/Meta requests use their own signatures and do not need Texto's email shared secret. Configure Laravel trusted proxies so signed Twilio URLs match the public scheme, host, port, path and query. Preserve raw bodies and form values through middleware/proxies.

Telnyx timestamps are checked within `TELNYX_WEBHOOK_TOLERANCE` seconds (default 300). `TELNYX_WEBHOOK_SECRET` remains a fallback name for the **public Ed25519 key**, not the API key. Email has no universal provider schema: adapt provider payloads to:

```json
{"event":"inbound","message_id":"provider-id","from":"sender@example.com","to":"inbox@example.com","subject":"Hello","text":"Message body","attachments":[]}
```

```json
{"event":"status","message_id":"correlated-outbound-id","status":"delivered"}
```

Use the secret header where supported. Missing/invalid secrets or signatures and malformed required fields return 403. Meta batches are handled item by item. Configure `TEXTO_WEBHOOK_RATE_LIMIT` (default 300 successful authenticated requests per endpoint per minute); 429 responses include `Retry-After`. Use Laravel's shared cache in a multi-instance application. Providers may retry unsuccessful responses, so retain inbound IDs.

`TEXTO_TESTING_SKIP_WEBHOOK_VALIDATION` bypasses checks only when Laravel's environment is `testing`; it is not a production switch.

## Fallback polling

Set `TEXTO_STATUS_POLL_ENABLED=true`; Texto schedules `StatusPollJob` every minute. Run Laravel's scheduler and queue workers. Scheduler and execution locks prevent concurrent polling jobs. Only Twilio and Telnyx implement provider polling; email and WhatsApp rely on callbacks.

Defaults: minimum age 60 seconds, maximum attempts 5, queued-without-ID attempts 2, backoff 300 seconds, eligible batch size 100. Configure the `TEXTO_STATUS_POLL_*` variables in `config/texto.php`. Exhausted/cooling-down rows do not consume the eligible batch limit. Missing provider IDs become `Ambiguous` at their limit; completed failures do not automatically resend.

Twilio Conversations uses its saved Conversation SID, not the classic Messages API. Any read recipient yields `Read`, otherwise any delivered recipient yields `Delivered`. A partial failure stays pending; only all-recipient failure becomes `Failed`/`Undelivered`, with detailed paginated receipts used for mixed failure aggregates. Signed `onDeliveryUpdated` callbacks follow the same policy; a failed individual receipt is resolved against the aggregate. Configure that event on the service/global post-action webhook when using it; Texto manages only its matching conversation-scoped URL and never overwrites global integration settings.

## Extension points and architecture

`Texto` coordinates address resolution, effective configuration, persistence and events. `DriverManager` creates isolated senders; adapters own provider HTTP protocols. Webhook handlers validate and normalize payloads into `WebhookProcessingResult`. `MessageRepositoryInterface` owns persistence and status progression. Jobs reuse these same services.

Override a built-in sender in a service provider:

```php
use Awaisjameel\Texto\Contracts\DriverManagerInterface;

$this->app->afterResolving(DriverManagerInterface::class, function ($manager) {
    $manager->extend('twilio', fn (array $configuration) => new MyTwilioSender($configuration));
});
```

The factory receives effective per-send/snapshot configuration and must return `MessageSenderInterface`. Its `send()` accepts `AddressInterface` values and returns `SentMessageResult`. A `PollableMessageSenderInterface` additionally exposes `fetchStatus(string $providerMessageId, mixed ...$context)`. Extensions replace one of the four `Driver` enum cases; arbitrary new names are rejected. Bind `MessageRepositoryInterface` to replace persistence. Custom implementations must support the new optional configuration arguments described in [MIGRATION.md](MIGRATION.md).

API adapters are instantiated per sender rather than resolved as credential-capturing global singletons. Inject custom/fake adapters through sender constructors. Direct adapter construction with no configuration argument captures current driver settings once. Convenience `Http::twilio()`, `Http::telnyx()` and `Http::whatsapp()` macros use current configuration; senders use their own captured configuration.

## Development

```sh
composer install
composer test
composer analyse
vendor/bin/pint --test
composer test-coverage
```

Tests include actual encrypted database queue execution, migrations, provider protocol fixtures, signed raw webhooks, delivery progression, media preservation, polling fairness and credential isolation. Never use real provider credentials for unit tests. The CI matrix covers maintained PHP/Laravel pairs on Linux and Windows, including minimum dependencies.

`php artisan texto:test-send <to> <body>` sends through the configured driver and reports failures with a nonzero exit status. Use only an authorized recipient/account.

See [CHANGELOG.md](CHANGELOG.md) and [LICENSE.md](LICENSE.md). Include a failing regression when contributing a bug fix, and contact the maintainer privately for security issues.
