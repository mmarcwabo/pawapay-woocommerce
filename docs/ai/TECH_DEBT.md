# Technical Debt

## P0 - Critical

### TD-001 - Unsigned webhook completes orders
- Evidence: Callbacks now `GET /deposits/{id}` before `apply()`. Source `webhook` cannot complete.
- Impact: Residual: REST route is still public (by design); signed RFC 9421 ECDSA is not fully verified.
- Status: Mitigated. Target: stronger signature verify when dashboard signing is enabled.

### TD-002 - One deposit id per order
- Evidence: `process_payment` still overwrites `_pawapay_deposit_id` (1.x pointer). History is now in `{prefix}pawapay_transactions`.
- Impact: Latest-meta lookup can miss an older live deposit unless the attempts table is queried (webhook/poll `find_order` does that from 1.3.0).
- Status: Mitigated. Initiate lock and reuse landed in 1.4.0. Latest-meta pointer still overwritten for 1.x lookup.

## P1 - High

### TD-003 - Failed deposit fails the Woo order
- Evidence: Default is attempt `FAILED`, order stays pending. Optional setting `fail_woo_on_failed_deposit`.
- Status: Mitigated.

### TD-004 - Non-atomic completion
- Evidence: `claim_completion()` wins on the attempt row; loser no-ops. Memory path is test-only.
- Status: Mitigated. Residual: no DB `SELECT … FOR UPDATE` around `payment_complete()`.

### TD-005 - Full MSISDN in order notes
- Evidence: New initiation notes use `masked_msisdn`. `_pawapay_phone` meta still stores the full number for 1.x compatibility.
- Status: Mitigated. Remaining: stop writing full MSISDN meta (Phase 9).

## P2 - Medium

### TD-006 - No background reconciliation
- TD-007 - Gateway god class
- TD-008 - Static provider catalog (`/active-conf` unused)
- TD-009 - Token only in wp-admin options
- TD-010 - No HPOS compatibility declaration
- TD-011 - Classic checkout only (Blocks UNKNOWN)
- TD-012 - Poll every 3s, no backoff/rate limit
- TD-013 - Tests without Woo bootstrap

## P3 - Low

### TD-014 - UUID via `mt_rand`
### TD-015 - French gettext map instead of `.po`
