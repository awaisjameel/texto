# Texto

**Unified, extendable Laravel gateway for sending & receiving SMS/MMS via Twilio and Telnyx (Laravel 10+, PHP 7.4 || 8.2+)**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/awaisjameel/texto.svg?style=flat-square)](https://packagist.org/packages/awaisjameel/texto)
[![Tests](https://img.shields.io/github/actions/workflow/status/awaisjameel/texto/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/awaisjameel/texto/actions?query=workflow%3Atests+branch%3Amain)
[![Downloads](https://img.shields.io/packagist/dt/awaisjameel/texto.svg?style=flat-square)](https://packagist.org/packages/awaisjameel/texto)
[![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE.md)

Texto provides a clean SOLID API for sending and receiving SMS/MMS messages. It abstracts provider differences (Twilio, Telnyx) with a driver system that you can easily extend. It stores sent & inbound messages, processes delivery status webhooks, dispatches rich Laravel events, supports queue-based async sending, retry/backoff, and secure webhook validation.

---

## Features

-   SMS & MMS sending via Twilio or Telnyx (drivers)
-   Inbound message webhook handling & storage
-   Delivery status tracking (delivered / failed / queued / sent)
-   Automatic retry with exponential backoff (configurable)
-   Optional queued sending (`queue=true`)
-   Events: `MessageSent`, `MessageReceived`, `MessageFailed`, `MessageStatusUpdated`
-   Extendable driver registration via `Texto::extend()`
-   Strong typing: enums, value objects (`PhoneNumber`), strict types
-   Security: signature validation, optional shared secret header, rate limiting middleware
-   Configurable persistence (disable with `store_messages=false`)
-   CI ready (PHPStan, Pint, Pest)

---

## Support us

If you find this package useful, consider supporting its development make sure to give a star on GitHub!

## 1. Installation

Install via Composer:

```bash
composer require awaisjameel/texto
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="texto-migrations"
php artisan migrate
```

Publish the config file:

```bash
php artisan vendor:publish --tag="texto-config"
```

Environment variables (add to `.env`):

```env
TEXTO_DRIVER=twilio               # or telnyx
TEXTO_STORE_MESSAGES=true
TEXTO_QUEUE=false                 # enable to queue sending
TEXTO_RETRY_ATTEMPTS=3
TEXTO_RETRY_BACKOFF_START=200
TEXTO_WEBHOOK_SECRET=your-shared-secret   # optional extra security
TEXTO_DEFAULT_REGION=US

TWILIO_ACCOUNT_SID=xxx
TWILIO_AUTH_TOKEN=xxx
TWILIO_FROM_NUMBER=+15550001111

TELNYX_API_KEY=xxx
TELNYX_MESSAGING_PROFILE_ID=xxx
TELNYX_FROM_NUMBER=+15550002222
```

Views are not required for core messaging (placeholder only).

---

## 2. Configuration Overview

`config/texto.php` key sections:

-   `driver`: active driver (`twilio` | `telnyx`)
-   `store_messages`: persist to `texto_messages`
-   `queue`: async sending via `SendMessageJob`
-   `retry`: `max_attempts`, `backoff_start_ms`
-   `webhook.secret`: shared secret header (`X-Texto-Secret`)
-   `webhook.rate_limit`: per-minute rate limit for webhook endpoints
-   `validation.region`: default region for parsing non E.164 numbers

---

## 3. Usage

### 3.1 Basic Send

```php
use Texto; // facade alias

Texto::send('+15551234567', 'Hello world');
```

### 3.2 MMS / Media

```php
Texto::send('+15551234567', 'Check this out', [
	'media_urls' => ['https://example.com/image.jpg']
]);
```

### 3.3 Driver Override Per Message

```php
Texto::send('+15551234567', 'Testing Telnyx', [
	'driver' => 'telnyx'
]);
```

### 3.4 Queued Sending

Enable `TEXTO_QUEUE=true`. Then `Texto::send()` returns a queued placeholder (status `queued`). Ensure a queue worker is running:

```bash
php artisan queue:work
```

### 3.5 Events

Listen in `EventServiceProvider`:

```php
protected $listen = [
	\Awaisjameel\Texto\Events\MessageSent::class => [/* listeners */],
	\Awaisjameel\Texto\Events\MessageReceived::class => [],
	\Awaisjameel\Texto\Events\MessageFailed::class => [],
	\Awaisjameel\Texto\Events\MessageStatusUpdated::class => [],
];
```

### 3.6 Accessing Stored Messages

```php
use Awaisjameel\Texto\Models\Message;

$recent = Message::latest()->take(10)->get();
```

---

## 4. Webhooks

Routes (auto-registered):

-   Inbound: `/texto/webhook/twilio`, `/texto/webhook/telnyx`
-   Status: `/texto/webhook/twilio/status`, `/texto/webhook/telnyx/status`

Configure these in Twilio/Telnyx consoles pointing to your domain. Include the `X-Texto-Secret` header if configured.

### 4.1 Security

-   Twilio signature validation via `RequestValidator` (skippable in tests)
-   Telnyx signature placeholder (extend when you add secret validation)
-   Optional shared secret header `X-Texto-Secret`
-   Rate limiting middleware (configurable per minute)

---

## 5. Extending Drivers

```php
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\ValueObjects\{PhoneNumber, SentMessageResult};
use Awaisjameel\Texto\Enums\{Driver, Direction, MessageStatus};

Texto::extend('custom', function () {
	return new class implements MessageSenderInterface {
		public function send(PhoneNumber $to, string $body, ?PhoneNumber $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult {
			// Implement API call...
			return new SentMessageResult(
				Driver::Twilio, // or define a new enum case if you fork
				Direction::Sent,
				$to,
				$from,
				$body,
				$mediaUrls,
				$metadata,
				MessageStatus::Sent,
				'custom-123',
			);
		}
	};
});
```

---

## 6. Retry & Backoff

Configured via `retry.max_attempts` and `retry.backoff_start_ms`. Each attempt doubles the delay. Exceptions bubble with `TextoSendFailedException`.

---

## 7. Testing & Fakes

Use the included `FakeSender` driver for isolated tests:

```php
app(\Awaisjameel\Texto\Contracts\DriverManagerInterface::class)
	->extend('twilio', fn() => new \Awaisjameel\Texto\Drivers\FakeSender());
```

Run the suite:

```bash
composer test
```

---

## 8. Data Model

Table: `texto_messages`

| Column                                    | Notes                                        |
| ----------------------------------------- | -------------------------------------------- |
| direction                                 | `sent` or `received`                         |
| driver                                    | `twilio` / `telnyx`                          |
| from_number / to_number                   | E.164 strings                                |
| body                                      | Text body (nullable inbound when media only) |
| media_urls                                | JSON array of media URLs                     |
| status                                    | Current status (enum values)                 |
| provider_message_id                       | Twilio SID / Telnyx ID                       |
| error_code                                | Provider error code if failed                |
| metadata                                  | Arbitrary JSON                               |
| sent_at / received_at / status_updated_at | Timestamps                                   |

---

## 9. Roadmap / Future Ideas

-   Multi-driver failover & weighted routing
-   Additional providers (MessageBird, Vonage, etc.)
-   Template/content API helpers
-   Bulk sending & batching

---

## 10. Contributing

PRs welcome. Please:

1. Run `composer analyse && composer format`.
2. Include tests for new behavior.
3. Document public changes in README + CHANGELOG.

---

## 11. Security

Report vulnerabilities via GitHub Security Advisories. Avoid posting sensitive logs in issues.

---

## 12. License

MIT License (see `LICENSE.md`).

---

## 13. Credits

Created by [awaisjameel](https://github.com/awaisjameel). Inspired by Spatie's package skeleton.

## Usage

```php
$texto = new Awaisjameel\Texto();
echo $texto->echoPhrase('Hello, Awaisjameel!');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

-   [awaisjameel](https://github.com/awaisjameel)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
