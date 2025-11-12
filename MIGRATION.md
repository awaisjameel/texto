# Migration from twilio/sdk to Direct REST

This document summarizes the transition removing the `twilio/sdk` dependency in favor of Laravel's HTTP client with first-class adapters.

## Summary

- Removed `twilio/sdk` from `composer.json`.
- Added HTTP macro `Http::twilio()` for consistent auth + base URL handling.
- Implemented adapter interfaces:
  - `TwilioMessagingApiInterface`
  - `TwilioConversationsApiInterface`
  - `TwilioContentApiInterface`
- Concrete implementations live under `src/Support/*Api.php` using `Http`.
- Refactored `TwilioSender` to consume adapters instead of SDK client objects.
- Replaced `Twilio\Security\RequestValidator` with custom `TwilioSignatureValidator` (HMAC-SHA1).
- Added granular exception hierarchy (`TwilioApi*Exception`) for clearer error handling.
- Updated webhook handler to use new signature validator.
- Added tests covering messaging, conversations, content templates, and webhook validation.

## New Error Handling Strategy

Responses are inspected for HTTP status and Twilio error `code` field.

- 400 -> Validation (`TwilioApiValidationException`)
- 401/403 -> Auth (`TwilioApiAuthException`)
- 404 -> Not Found (`TwilioApiNotFoundException`)
- 429 -> Rate Limit (`TwilioApiRateLimitException`)
- Other -> Generic (`TwilioApiException`)

Retry/backoff uses existing `Retry::exponential` with values sourced from `config('texto.twilio.retry.*')` (fallback to `texto.retry`).

## Signature Validation

Previous SDK logic: `RequestValidator`.
Now: `TwilioSignatureValidator::validate($token, $url, $params, $signature)`.
Ordering rule: ASCII sort keys, append key+value to URL, HMAC-SHA1, Base64 encode.

## Conversation Flow Changes

- Conversation creation, participant add, message send, webhook attach now pure REST endpoints.
- Duplicate participant detection relies on Twilio error `code` 50416; conversation SID parsed from error message (logic retained).

## Content Templates

- Template ensure logic moved to `TwilioContentApi`.
- Fallback casing attempts (snake_case and TitleCase) preserved.

## Testing Strategy

Uses `Http::fake()` with URL pattern matching for Twilio endpoints:

- `api.twilio.com/2010-04-01/Accounts/*/Messages.json`
- `conversations.twilio.com/v1/...`
- `content.twilio.com/v1/Content`

## How to Upgrade

1. Remove `twilio/sdk` from your downstream app `composer.json` if explicitly required.
2. Publish new config (already auto-published via `texto:install`): `config/twilio.php`.
3. Set environment variables:
   ```env
   TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   TWILIO_AUTH_TOKEN=your_auth_token
   TWILIO_FROM_NUMBER=+15550001111
   ```
4. Run tests to verify functionality: `composer test`.

## Potential Follow-ups

- Implement rate limit adaptive backoff (inspect `TwilioApiRateLimitException`).
- Add caching layer for Content template lookups.
- Provide optional async dispatch for Conversations create + send.

## Rollback Plan

If any issue arises, re-add `"twilio/sdk": "^8.8"` and revert `TwilioSender` to prior implementation (git revert commit). All adapters are additive and can coexist temporarily.

---

This migration reduces dependency surface, improves testability, and keeps feature parity with the former SDK implementation.
