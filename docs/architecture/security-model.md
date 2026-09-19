# Security model

## Trust boundaries (target)

| Source | Trust |
|---|---|
| Checkout POST (phone, operator, currency) | Untrusted input. Validate only. Never trust amount. |
| Thank-you JS / query string | Untrusted. Poll may *request* a check; it cannot *declare* paid. |
| Public webhook POST | Untrusted until signature (or equivalent) is verified. Then still re-validate deposit vs attempt. |
| Authenticated `GET /deposits/{id}` with merchant token | Trusted transaction status. |
| `wp-config` / env API token | Secret. Never localize to JS. |

## Current controls (2.2.0)

**Present**

- Callbacks confirm with `GET /deposits/{id}` before `payment_complete()`.
- Amount/currency matched to the frozen attempt. Duplicate COMPLETED is claimed once.
- Amount taken from the Woo order, not from the form.
- Operator and currency checked against enabled lists.
- MSISDN composed/validated server-side (`7–15` digits).
- Poll requires `order_key` + nonce; guest poll is possible if the key leaks (same as Woo thank-you). The JSON is a customer-safe DTO (no deposit id, token, or full MSISDN).
- Debug logs mask MSISDN (`WC_PawaPay_API::redact_for_log`).
- Webhook does not log the raw body (only deposit id + status).

**Missing or weak**

- REST `permission_callback` is still `__return_true`; the body is no longer authoritative.
- Full RFC 9421 ECDSA verification is not implemented. Optional digest + Signature-Date gate only.
- `_pawapay_phone` meta still stores the full MSISDN.
- API token lives in `woocommerce_pawapay_settings` only (no `WC_PAWAPAY_API_TOKEN` constant).
- Poll has adaptive backoff but no server-side rate limit.

## Target controls

1. Verify signed callbacks when enabled; otherwise treat webhook as a *hint* and always `GET /deposits/{id}` before completion.
2. Completion only if deposit belongs to the attempt, amounts/currencies match (requested vs frozen attempt), status is final `COMPLETED`.
3. Idempotent completion (unique deposit, attempt already final → HTTP 200, no second `payment_complete`).
4. Token from env / `wp-config` first; admin field masked.
5. Structured logs with order / attempt / deposit ids; masked MSISDN everywhere including notes (or notes show mask only).
6. Rate-limit customer poll and initiate; do not rate-limit verified PawaPay callbacks.

## Maungano hosting notes

Wordfence / Coming Soon can block callbacks. Production callback IPs are documented by PawaPay. **UNKNOWN:** whether the live VPS currently allows those IPs.
