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

### 2026-09-19 - Hardening + release notes (Phase 9–10)

**Task**
Token constant, poll GET throttle, HPOS declare, no Blocks claim, masked phone meta, script tests + changelog.

**Components affected**
Settings policy, poll policy, thank-you, gateway meta/API token, UUID generator, plugin bootstrap, `tests/harden-test.php`.

**Behavior changed**
`WC_PAWAPAY_API_TOKEN` wins. Poll skips PawaPay GET if the same order was looked up in the last 2 seconds. HPOS compatible. New `_pawapay_phone` is masked.

**Database**
None.

**Dependencies**
None.

**Tests**
`php tests/harden-test.php` plus existing suite.

**Security review**
PASS WITH CONDITIONS. Token stays server-side. Poll auth still precedes throttle. Throttle is best-effort (check-then-set). Do not hook `woocommerce_pawapay_api_token` to output the secret.

**Verifier**
PASS. Suite including `tests/harden-test.php` green. HPOS declared; Blocks not claimed.

**Architecture**
ADR-010. Version 2.4.0.

**Known limitations**
Blocks untested. RFC 9421 ECDSA not claimed. Old phone meta not rewritten.

**Follow-up**
GitHub release `v2.4.0`.

### 2026-09-19 - Admin attempts + cached catalog (Phase 7–8)

**Task**
List payment attempts in wp-admin. Cache `/active-conf`. Hint operator only when the prefix is unique.

**Components affected**
Admin view/page, repository `find_recent`, gateway order actions, catalog policy, checkout JS, client `get_active_configuration`, `tests/admin-test.php`, `tests/catalog-test.php`.

**Behavior changed**
Managers see masked attempts and GET a chosen deposit id. Checkout filters operators by cached active-conf. DRC 24389/24397/24381 may pre-select an operator; the customer can override.

**Database**
None.

**Dependencies**
None.

**Tests**
`php tests/admin-test.php` and `php tests/catalog-test.php` plus existing suite.

**Security review**
PASS WITH CONDITIONS. Check status is POST + nonce + `manage_woocommerce`. Catalog cache is sandbox/live scoped. Residual: no admin GET rate limit.

**Verifier**
PASS WITH CONDITIONS. `$theorder` is only a fallback for older Woo. Extra MNOs stay after live-conf. Detection does not invent a provider.

**Architecture**
ADR-008, ADR-009. Version 2.3.0.

**Known limitations**
Operator auto-detect is UNKNOWN under number portability. No server poll rate limit yet.

**Follow-up**
Phase 9 hardening.

### 2026-09-19 - Action Scheduler reconciliation (Phase 6)

**Task**
GET stale ACCEPTED/UNKNOWN attempts in the background when the customer leaves and the webhook is late.

**Components affected**
`WC_PawaPay_Reconciliation_Policy`, `WC_PawaPay_Reconciler`, attempt repository window/touch, `Deposit::sync_deposit`, `tests/reconcile-test.php`.

**Behavior changed**
Recurring Action Scheduler hook every 5 minutes. Up to 10 GETs per tick, by attempt deposit id, with age backoff. Same completion policy as poll (`source=reconciliation`).

**Database**
None (uses `updated_at` for backoff).

**Dependencies**
WooCommerce Action Scheduler (already bundled).

**Tests**
`php tests/reconcile-test.php` plus existing script tests.

**Security review**
PASS. Hook is not public. GET uses stored attempt ids. Deactivation now always loads the class before unscheduling. Non-2xx GET does not apply.

**Verifier**
PASS. Historical deposit ids selected; FAILED/fresh skipped; missing order does not GET.

**Architecture**
ADR-007. Version 2.2.0.

**Known limitations**
No GET after 48 hours except admin. No schedule if Action Scheduler is absent.

**Follow-up**
Phase 7 admin attempts panel.

### 2026-09-19 - Waiting UX + adaptive poll (Phase 5)

**Task**
Dedicated waiting copy; poll returns a customer-safe DTO; retry uses Woo order-pay.

**Components affected**
`WC_PawaPay_Poll_Policy`, thank-you / order-pay render, `pawapay-thankyou.js`, checkout CSS, `tests/poll-test.php`.

**Behavior changed**
Waiting card on thank-you and order-pay. Poll backs off 3s → 15s. JSON has no deposit id or full MSISDN. Failed attempts link to Woo pay; active attempts hide Place order.

**Database**
None.

**Dependencies**
None.

**Tests**
`php tests/poll-test.php` plus existing script tests.

**Security review**
PASS WITH CONDITIONS. Guest poll + no server rate limit remain Phase 9 residuals. Applied hash_equals, last-4 remask, order-pay CSS hide, same-origin pay URL check.

**Verifier**
PASS WITH CONDITIONS. Failed/cancelled pages no longer enqueue poll JS, so they cannot infinite-reload. Poll script only reloads after a poll response.

**Architecture**
ADR-006. Version 2.1.0.

**Known limitations**
Poll AJAX is still unrate-limited. No Action Scheduler if the customer leaves.

**Follow-up**
Phase 6 reconciliation.

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

