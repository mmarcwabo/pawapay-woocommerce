# Architecture Decision Log

### ADR-001 - Woo owns the order; PawaPay owns the transaction

**Status:** Accepted (product). Implementation of attempts/verification is Proposed.

**Context**
Maungano live Mobile Money works on plugin 1.2.1 with a single deposit id on the order and an unsigned webhook.

**Decision**
Evolve to PaymentAttempt rows and trusted completion only via verified callback or authenticated status GET. Do not rewrite the live v1 `POST /deposits` path in the same step as a v2 cutover.

**Alternatives considered**
Big-bang rewrite to PSR-4 `src/` and v2 only — rejected; live money already uses v1.

**Consequences**
2.0.0 when schema + completion rules ship. 1.x meta remains readable.

**Migration / rollback impact**
Backfill optional; keep `_pawapay_deposit_id`.

**Date**
2026-09-19

### ADR-002 - Dual-write payment attempts beside 1.x order meta

**Status:** Accepted.

**Context**
1.2.1 stored only `_pawapay_deposit_id`. A retry lost the previous PawaPay deposit.

**Decision**
Add `{prefix}pawapay_transactions` (schema v1) and write a new row per deposit. Keep writing 1.x meta for one release so existing status lookup and old orders keep working. Do not store full MSISDN in the table. Do not change `payment_complete()` rules in the same step.

**Alternatives considered**
Replace meta immediately — rejected; live orders and admin “Check PawaPay status” still read the latest meta key.

**Consequences**
Plugin 1.3.0. `find_order()` prefers the attempts table, then meta, then backfills.

**Migration / rollback impact**
`dbDelta` on activate/`plugins_loaded`. Deactivate 1.3.0 and restore 1.2.1: table can remain unused; meta path still works.

**Date**
2026-09-19

### ADR-003 - Client interface now; v1 HTTP until an explicit cutover

**Status:** Accepted.

**Context**
Live Maungano deposits use Merchant API v1 `POST /deposits`. A v2 client in the same step would change money movement.

**Decision**
Add `WC_PawaPay_Client`. `WC_PawaPay_API` implements it and keeps v1 paths. Do not add a v2 client or call `/v2/deposits` until product decides. Gateway and poll type-hint the interface.

**Alternatives considered**
Ship a stub `V2Client` — rejected; unused money-path code is a footgun.

**Consequences**
Plugin 1.3.1. A later phase can add a v2 adapter behind the same interface.

**Migration / rollback impact**
None for merchants. `get_api()` still returns `WC_PawaPay_API`.

**Date**
2026-09-19

### ADR-004 - Initiate lock and reuse before a second live deposit

**Status:** Accepted.

**Context**
Double-click, refresh, and timeout retries could `POST /deposits` twice for one Woo order.

**Decision**
`WC_PawaPay_Payment_Service` freezes amount/currency from the order, acquires a 90s lock during the POST, reuses an active attempt with the same phone/provider/amount, and blocks a new deposit while another attempt is still active. Timeout/connection → attempt `UNKNOWN`, Woo `success` + pending, no second POST for that attempt.

**Alternatives considered**
Fail the Woo order on timeout — rejected; the customer may already have been debited.

**Consequences**
Plugin 1.4.0. Changing phone while an attempt is ACCEPTED/UNKNOWN requires waiting (Phase 5 “try another number”).

**Migration / rollback impact**
None. 1.x meta still written.

**Date**
2026-09-19

### ADR-005 - Callback is a hint; GET confirms before paid

**Status:** Accepted.

**Context**
1.2.1 applied unsigned callback JSON, including forged `COMPLETED`.

**Decision**
Never complete from source `webhook`. The public callback looks up `GET /deposits/{id}` and applies that payload as `status-lookup`. Optional signed-callback setting rejects missing/invalid Content-Digest and Signature-Date. Woo `failed` on PawaPay FAILED is opt-in.

**Alternatives considered**
Trust signed body without GET — deferred until RFC 9421 ECDSA verify is proven.

**Consequences**
Plugin 2.0.0. PawaPay retries on HTTP 503 if GET fails.

**Migration / rollback impact**
Callback URL unchanged. Leave dashboard signing off unless the plugin setting is on.

**Date**
2026-09-19

### ADR-006 - Waiting DTO and Woo pay-page retry

**Status:** Accepted.

**Context**
Thank-you was a banner plus a fixed 3s poll that returned Woo status names. A failed attempt left the customer without a clear retry. Adding a custom Pay button would bypass Woo `process_payment`.

**Decision**
Poll returns a sanitized DTO (`phase`, `paid`, `reload`, `can_retry`, masked phone). Delays follow `WC_PawaPay_Poll_Policy`. Retry is Woo `get_checkout_payment_url()` only. An active attempt hides Place order on order-pay.

**Alternatives considered**
A plugin-owned “Pay again” POST — rejected; it would bypass checkout validation and the initiate lock.

**Consequences**
Plugin 2.1.0. Server-side poll rate limits stay Phase 9.

**Migration / rollback impact**
None for deposits. Older poll JS expecting `{status,paid,reload}` is replaced with this version’s script.

**Date**
2026-09-19
