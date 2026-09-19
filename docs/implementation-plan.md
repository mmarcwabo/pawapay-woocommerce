# Implementation plan

**Do not bump to 2.0.0 until Phase 1–4 ship together** (schema + initiation + verified completion). Phase 1 shipped as **1.3.0**.

Rollback: deactivate 2.x and restore 1.2.1; 1.x meta still present. New table can remain unused.

## Phase 0 — Audit (this document set)

**Status:** done 2026-09-19.

No runtime change.

## Phase 1 — PaymentAttempt + repository

**Status:** done 2026-09-19 (plugin **1.3.0**). Completion rules still 1.2.1.

**Goal:** Durable attempts; stop overwriting deposit history.

**New:** `includes/class-wc-pawapay-migrator.php`, `includes/class-wc-pawapay-attempt.php`, `includes/class-wc-pawapay-attempt-repository.php`, activate/`plugins_loaded` upgrade.

**Touch:** `wc-pawapay-gateway.php` (boot), keep writing 1.x meta for one release.

**Tests:** repository insert uniqueness; find by deposit_id; backfill from mock order meta.

**Security:** no full MSISDN column.

**Rollback:** option to stop writing the table; Woo still uses meta.

## Phase 2 — PawaPay client interface

**Goal:** Isolate v1 HTTP. Prepare v2 later.

**New:** `ClientInterface`, keep `WC_PawaPay_API` as `V1Client` adapter. Do not call v2 `/v2/deposits` until product decides (current live path is **v1** `POST /deposits`).

**Tests:** statement sanitizer (exists); redact; timeout/error normalization.

## Phase 3 — Initiation + idempotency

**Goal:** Thin gateway; lock; freeze amounts; no duplicate live deposits.

**Touch:** `process_payment` → `PaymentService::initiate`. Frontend disable Place order after first submit.

**Rules:** amount/currency from order; POST only phone/operator/currency choice.

**If initiate times out:** attempt `UNKNOWN`, do not fail the Woo order, do not create a second deposit for the same idempotency key.

**Tests:** double submit; missing rate; invalid phone.

## Phase 4 — Webhook + state machine

**Goal:** Hint + verify. Signed callbacks when enabled; always `GET` before complete if unsigned.

**Touch:** `class-wc-pawapay-webhook.php` → verifier + processor. `Deposit::apply` becomes attempt-aware and amount-checked.

**Woo `failed`:** only if product policy says so; default keep order `pending` on attempt failure.

**Tests:** forged COMPLETED; duplicate COMPLETED; amount mismatch; already paid.

## Phase 5 — Waiting UX + adaptive poll

**Goal:** Dedicated waiting copy; poll returns safe DTO only; server still GETs PawaPay.

**Touch:** thank-you / order-pay templates, `pawapay-thankyou.js`.

**Do not** add a second Pay button that bypasses Woo.

## Phase 6 — Action Scheduler reconciliation

**Goal:** Find `ACCEPTED`/`UNKNOWN` older than N minutes; GET; backoff.

**Requires:** Woo Action Scheduler (already on Maungano via Woo).

## Phase 7 — Admin panel

**Goal:** Attempts list, masked phone, check status, no secrets.

**Replace** global `$theorder` action-only UX.

## Phase 8 — `/active-conf` cache + provider detection

**Goal:** Transient 15–60 min; static catalog as fallback. Detect MNO from MSISDN where prefixes allow; show friendly label; keep override.

**DRC reality:** prefixes overlap; auto-detect may be **partial**. Document UNKNOWN accuracy; never invent a provider.

## Phase 9 — Hardening

Token constant, poll rate limit, HPOS compatibility declaration (after verifying `wc_get_orders` meta queries), do not claim Blocks until tested.

## Phase 10 — Tests + README + 2.0.0

PHPUnit or keep script tests plus new ones. Mermaid already in these docs. CHANGELOG migration notes.

## Explicitly deferred (document, do not fake)

- WooCommerce Blocks checkout — Classic works today; Blocks untested.
- Signed **outbound** financial requests (dashboard public key) — would break current Bearer POST.
- SMS/WhatsApp abandoned-cart reminders.
- App/Flutter changes — out of this repo.
- Moving live traffic to PawaPay API v2 in the same release as the table — isolate client first, cut over later.
