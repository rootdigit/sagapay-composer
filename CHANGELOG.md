# Changelog

## v1.2.0 — 2026-10-27

### Security
- `WebhookHandler` verifies `X-Sagapay-Merchant-Signature`, keyed with your own signing secret (dashboard: Webhooks → Signing secret). It no longer reads the retired `X-Sagapay-Signature` header, which was keyed with a secret shared by every merchant.
- A `WebhookHandler` without a signing secret now rejects every notification. Previously it skipped signature verification. Pass the secret as the second constructor argument.
- `Util::verifySignature()` rejects a malformed digest and an empty secret, and accepts upper-case hex. `processWebhook()` rejects a repeated signature header and a body that is not a JSON object.
- Requests never follow redirects, and a 3xx answer is an error, so your `x-api-key` and `x-api-secret` headers are never sent to another host.

### Added
- `Util::isIpnFresh($payload, $maxAgeSeconds = 86400)` and the optional third `WebhookHandler` argument reject notifications whose `timestamp` is too old or more than 5 minutes in the future. Use them alongside processing each `id` only once.
- `WebhookHandler::SIGNATURE_HEADER` and `WebhookHandler::DEFAULT_MAX_AGE_SECONDS`.
- `Client::createWithdrawal($params, $idempotencyKey)` sends an `Idempotency-Key` header, so a retried request cannot create a second withdrawal. The key is checked locally so it cannot inject other headers.
- Dependency-free tests: `composer test`.

### Fixed
- No more `curl_close()` deprecation notice on PHP 8.5.
