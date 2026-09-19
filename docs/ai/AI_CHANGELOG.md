# AI Change Log

This is not the product changelog. It records significant AI-assisted engineering changes and their verification state.

## Entry template

### YYYY-MM-DD - Task title

**Task**
...

**Components affected**
...

**Behavior changed**
...

**Database**
None / details

**Dependencies**
None / details

**Tests**
...

**Security review**
Not required / Passed / Findings

**Verifier**
PASS / PASS WITH CONDITIONS / FAIL / Not run

**Architecture**
No structural change / ADR reference

**Known limitations**
...

**Follow-up**
...

### 2026-09-19 - Verified completion (Phase 4)

**Task**
Stop unsigned callbacks from marking orders paid. Match amount/currency. Keep Woo pending on failed attempts.

**Components affected**
Completion policy, webhook verifier/processor, `Deposit::apply`, webhook, gateway settings, `tests/completion-test.php`.

**Behavior changed**
Callbacks GET `/deposits/{id}` then apply as `status-lookup`. Source `webhook` cannot complete. Duplicate COMPLETED is claimed once. FAILED does not fail the Woo order by default.

**Database**
None.

**Dependencies**
None.

**Tests**
`php tests/completion-test.php` plus existing script tests.

**Security review**
PASS WITH CONDITIONS. Non-2xx GET now 503; REST headers normalized; amount re-checked on `complete_order_only`. RFC 9421 ECDSA still not claimed.

**Verifier**
PASS WITH CONDITIONS. Poll now honors fail-order setting. Docs updated.

**Architecture**
ADR-005. Version 2.0.0.

**Known limitations**
RFC 9421 ECDSA not fully implemented. Signed mode is digest + date + header presence.

**Follow-up**
Phase 5 waiting UX.

### 2026-09-19 - Initiation lock (Phase 3)

**Task**
Prevent duplicate live deposits and freeze amounts on the server.

**Components affected**
Payment service, initiation policy/lock, `process_payment`, checkout JS, `tests/initiate-test.php`.

**Behavior changed**
Double submit reuses an active attempt. Timeout is UNKNOWN and Woo stays pending. Order notes mask the phone.

**Database**
None (uses existing attempts table + `wc_pawapay_init_lock_{order_id}` option).

**Dependencies**
None.

**Tests**
`php tests/initiate-test.php` plus existing script tests.

**Security review**
PASS WITH CONDITIONS. Unsigned webhook can still clear an active attempt (Phase 4). Added refuse-on-COMPLETED, empty currency fail-closed, ambiguous 4xx → UNKNOWN.

**Verifier**
PASS.

**Architecture**
ADR-004.

**Known limitations**
Cannot start a second number while an attempt is still active. Unsigned webhook completion unchanged.

**Follow-up**
Phase 4 verified callbacks + state machine.

### 2026-09-19 - PawaPay client interface (Phase 2)

**Task**
Isolate v1 HTTP behind `WC_PawaPay_Client` without calling `/v2/deposits`.

**Components affected**
`includes/class-wc-pawapay-client.php`, `includes/class-wc-pawapay-api.php`, gateway/deposit type hints, `tests/client-test.php`.

**Behavior changed**
Invalid JSON and transport failures now always include `error` / `error_type`. Checkout and poll already treated `error` or HTTP >= 500 as failure. Live URL remains v1 `/deposits`.

**Database**
None.

**Dependencies**
None.

**Tests**
`php tests/client-test.php` plus existing script tests.

**Security review**
PASS WITH CONDITIONS. Authenticated calls now set `redirection => 0`; debug omits unparsed bodies.

**Verifier**
PASS. Live path remains v1 `/deposits`.

**Architecture**
ADR-003.

**Known limitations**
No v2 adapter. No GET retry loop. Token still lives in gateway settings.

**Follow-up**
Phase 3 initiate lock.

### 2026-09-19 - Payment attempts table (Phase 1)

**Task**
Add PaymentAttempt storage and dual-write without changing Woo completion rules.

**Components affected**
`wc-pawapay-gateway.php`, `includes/class-wc-pawapay-gateway.php`, `includes/class-wc-pawapay-deposit.php`, new attempt/migrator/repository classes, `tests/attempt-test.php`.

**Behavior changed**
Each `POST /deposits` inserts a new attempt row. Webhook/poll can find an order by a previous deposit id. 1.x meta still overwritten with the latest deposit.

**Database**
`{prefix}pawapay_transactions` schema v1. Option `wc_pawapay_schema_version`. No full MSISDN column.

**Dependencies**
None.

**Tests**
`php tests/attempt-test.php` plus existing script tests.

**Security review**
PASS WITH CONDITIONS. Schema version is written only after the table exists. Unsigned webhook and initiate lock remain Phase 3/4.

**Verifier**
PASS WITH CONDITIONS. Docs and schema-version retry updated after review.

**Architecture**
ADR-002.

**Known limitations**
Unsigned webhook and FAILED→order failed unchanged. No initiate lock yet.

**Follow-up**
Phase 2 API client seam, then Phase 3 initiate lock.

### 2026-09-19 - Payment architecture audit (Phase 0)

**Task**
Full 1.2.1 codebase audit vs production-grade spec. No runtime refactor.

**Components affected**
`docs/architecture/*`, `docs/implementation-plan.md`, `docs/ai/*`.

**Behavior changed**
None.

**Database**
None.

**Dependencies**
None.

**Tests**
Not run (docs only).

**Security review**
Findings recorded (TD-001 unsigned webhook). Not a code change.

**Verifier**
Not run

**Architecture**
ADR-001 proposed implementation path.

**Known limitations**
Blocks and DRC auto-detect accuracy remain UNKNOWN.

**Follow-up**
Phase 1 only after explicit implement go-ahead.

