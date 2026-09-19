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
