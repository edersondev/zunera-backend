# Authentication Mail Operations

Authentication recovery and password-change notifications use queued mail and
include `X-Zunera-Message-ID` as a lowercase RFC 4122 UUID.

The delivery-event gateway must send only canonical event data:
`event_id`, `message_id`, `status`, and `occurred_at`. It must not include
recipient address, subject, body, token, or provider-specific payload.

Every event request must include `X-Zunera-Timestamp` and
`X-Zunera-Signature`. The signature format is `v1=<lowercase hex>`, where the
hex value is HMAC-SHA256 over the ASCII Unix timestamp, one literal period, and
the exact raw request body bytes. Requests outside the configured five-minute
replay window are rejected.

Release evidence requires at least 95 delivered canonical events from 100 valid
account recovery messages within five minutes. Alert when queue age exceeds five
minutes or delivery evidence drops below 95%.

Incident steps:

1. Pause recovery mail workers if duplicate or malformed events appear.
2. Verify gateway signature secret and replay-window clock skew.
3. Inspect queue age and failed jobs without logging tokens or recipient data.
4. Re-run `php artisan auth-mail:verify-delivery --samples=100`.
