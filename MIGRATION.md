# Upgrading to Texto 4

Version 4 is a major release. Read this before updating workers or processing new messages.

1. Use PHP 8.2-8.5 and Laravel 12/13 (Laravel 13 needs PHP 8.3+). Enable Sodium. Update Composer dependencies; older Laravel/PHP support is removed.
2. Pause send/poll workers during deployment. Back up the database and retain `APP_KEY`. Publish `texto-migrations`, review the new `upgrade_texto_message_credentials` migration, and run `php artisan migrate --force` before resuming sends. It adds nullable TEXT `driver_config` to the existing table. Do not recreate the original messages table.
3. Merge new keys from `config/texto.php` into published configuration. `texto:install` now preserves existing files by default and returns nonzero on publishing/migration failure. `--force` overwrites published files and should be deliberate.
4. Every newly stored outbound message has an encrypted effective configuration snapshot; jobs are also encrypted. All worker processes need the same encryption key. Keep previous keys available during rotation. Old rows without snapshots fall back to current configuration. Do not expose/decrypt credentials in API resources or logs.
5. Restart workers. Send jobs have one queue attempt, a ledger overlap lock, and skip rows already accepted/terminal. An unexpected failed job with no confirmed provider ID becomes `Ambiguous`. Reconcile unknown outcomes instead of blindly retrying a send. Coordinate queue `retry_after`, worker timeouts and shared cache locks. Jobs queued by older versions may lack snapshots; drain/reconcile them before switching.
6. Check Twilio media/templates and webhook setup below. Unsupported Conversations media now fails clearly instead of losing attachments or changing providers.

## Configuration and integration changes

- Effective `driver_config` is isolated to each sender. No temporary global configuration mutation remains. Overrides also work when `driver` is omitted. Default sender resolution uses the effective configuration.
- `DriverManagerInterface::sender(?Driver $driver = null, ?array $configuration = null)` now accepts an explicit snapshot. Extension factories receive that effective array. Update custom manager implementations.
- `MessageRepositoryInterface::storeSent(SentMessageResult $result, ?array $configuration = null)` and `upgradeQueued(int $id, SentMessageResult $result, ?array $configuration = null)` now accept the configuration snapshot. Custom repositories must implement these signatures and protect secrets equivalently.
- `MessageSenderInterface::send()` accepts `AddressInterface` for recipient/sender. `PhoneNumber` and `EmailAddress` implement it. Use `value()` for portable addresses; reject unsupported address families in custom senders.
- Provider API adapters are no longer container singletons. Construct them per sender or inject an adapter into the sender constructor. Optional explicit configuration is captured on construction, including endpoint and timeout settings.
- `WebhookHandlerInterface::handle()` can return null for intentionally ignored authentic events (such as outbound Conversations echoes).
- `Message::$cost_estimate` is a decimal string; `segments` is an integer. `driver_config` is hidden from array/JSON serialization.
- Only known `Driver` enum values can be overridden; registering an unreachable arbitrary driver name throws.

## Provider behavior

- Telnyx signature verification now follows the actual protocol: `telnyx-signature-ed25519`, `telnyx-timestamp`, signed bytes `timestamp|raw-body`, and a base64 Ed25519 public key. Configure `TELNYX_PUBLIC_KEY`; the old `TELNYX_WEBHOOK_SECRET` variable is only a fallback name for that public key. Never substitute the API key.
- Twilio signs raw form values/full request URLs, or raw JSON via `bodySHA256`. Configure trusted proxies correctly and retain query encoding. Conversations `Media` JSON strings are decoded; media objects remain in metadata even when Twilio supplies a media SID rather than a URL.
- Twilio Conversations sends allow one media URL under `TWILIO_MMS_MEDIA_BASE_URL` (fallback `app.url`) and a caption up to 500 characters. Templates use `media_path` relative to that base, preserving nested paths/query encoding. Regenerate or explicitly configure an MMS template matching that base/path variable. Unsupported remote hosts, extra attachments, or missing templates fail instead of sending body-only or silently using classic Messages.
- Content requests use documented snake_case JSON. Failed lookup is not treated as permission to create another template. All relevant list pages are checked, and pagination cannot forward credentials to a foreign origin.
- Conversation-scoped webhooks preserve all other destinations and update only an exact URL match. Changing your URL leaves the old destination for explicit application cleanup. No implicit historical replay occurs. Texto does not modify global/service webhook settings.
- Delivery aggregation accepts any delivered/read recipient; it fails only if all recipients fail. Mixed failure summaries use individual receipt pages. Signed `onDeliveryUpdated` callbacks are supported; configure the service/global post-action event explicitly for that endpoint. Individual failures are checked against the aggregate.
- Unknown/ambiguous Conversations send outcomes do not trigger a duplicate fresh-conversation send or destructive cleanup. Only a known missing cached conversation triggers a fresh setup; only definitive client rejection permits cleanup.
- WhatsApp defaults to Graph API v26.0. Existing `WHATSAPP_BASE_URL` overrides remain explicit application choices. Provider permissions/templates/service windows still apply.
- Email uses the application's existing mail transport. Named addresses survive queueing, cancelled transport fails clearly, and its normalized webhook requires `TEXTO_WEBHOOK_SECRET` (prefer `X-Texto-Secret`).

## Data and event behavior

Failed queued sends upgrade the original ledger row instead of adding another row and leaving the first queued. Status writes reload/lock the row and share forward-only progression with polling. Inbound IDs are scoped by provider and direction; missing IDs cannot provide deduplication. With `store_messages=false`, callbacks emit DTO events without touching the database and applications own deduplication.

Polling applies batch limits to eligible rows, not exhausted or cooling-down rows, and records failed lookup attempts. Scheduler and queued execution both use overlap protection. Review new regression tests alongside your own provider/account/database integration tests before deployment.

Historical changes remain in [CHANGELOG.md](CHANGELOG.md); the previous SDK-specific migration notes are superseded by the current adapter/configuration contracts above.
