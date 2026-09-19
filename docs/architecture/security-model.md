# Security model

## Trust boundaries (target)

| Source | Trust |
|---|---|
| Checkout POST (phone, operator, currency) | Untrusted input. Validate only. Never trust amount. |
| Thank-you JS / query string | Untrusted. Poll may *request* a check; it cannot *declare* paid. |
| Public webhook POST | Untrusted until signature (or equivalent) is verified. Then still re-validate deposit vs attempt. |
| Authenticated `GET /deposits/{id}` with merchant token | Trusted transaction status. |
| `wp-config` / env API token | Secret. Never localize to JS. |

## Current controls (1.2.1)

**Present**

- Amount taken from the Woo order, not from the form.
- Operator and currency checked against enabled lists.
- MSISDN composed/validated server-side (`7–15` digits).
- Poll requires `order_key` + nonce; guest poll is possible if the key leaks (same as Woo thank-you).
- Debug logs mask MSISDN (`WC_PawaPay_API::redact_for_log`).
- Webhook does not log the raw body (only deposit id + status).

**Missing or weak**

- Webhook `permission_callback` is `__return_true`. Anyone who can POST JSON with a known `depositId` and `status: COMPLETED` can complete that order. Poll is safer because it asks PawaPay.
- No RFC 9421 callback signature verification. Dashboard “Sign all callbacks” is unused (and must stay off until we verify).
- No replay store / signature-date window.
- No amount/currency match against the frozen attempt before `payment_complete()`.
- Order notes still store the **full** MSISDN.
- API token lives in `woocommerce_pawapay_settings` only (no `WC_PAWAPAY_API_TOKEN` constant).
- Poll has no rate limit.
- `apply()` uses a non-atomic “already paid?” check (race between webhook, poll, admin).
- REST route is a second unsigned public writer of payment state.

## Target controls

1. Verify signed callbacks when enabled; otherwise treat webhook as a *hint* and always `GET /deposits/{id}` before completion.
2. Completion only if deposit belongs to the attempt, amounts/currencies match (requested vs frozen attempt), status is final `COMPLETED`.
3. Idempotent completion (unique deposit, attempt already final → HTTP 200, no second `payment_complete`).
4. Token from env / `wp-config` first; admin field masked.
5. Structured logs with order / attempt / deposit ids; masked MSISDN everywhere including notes (or notes show mask only).
6. Rate-limit customer poll and initiate; do not rate-limit verified PawaPay callbacks.

## Maungano hosting notes

Wordfence / Coming Soon can block callbacks. Production callback IPs are documented by PawaPay. **UNKNOWN:** whether the live VPS currently allows those IPs.
