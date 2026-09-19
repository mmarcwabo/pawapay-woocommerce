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
