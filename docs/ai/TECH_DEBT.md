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
- Evidence: New initiation notes use `masked_msisdn`. New `_pawapay_phone` writes are masked (2.4.0). Older rows may still hold a full number.
- Status: Mitigated. New writes are masked (2.4.0). Old rows may still hold a full number.

## P2 - Medium

### TD-006 - Background reconciliation window
- Evidence: Recurring `wc_pawapay_reconcile` GETs due attempts (2.2.0). Stops after 48 hours.
- Status: Mitigated. Residual: no job if Action Scheduler is missing; admin still needed for very old rows.

### TD-007 - Gateway god class
- TD-008 - `/active-conf` cached (2.3.0); static catalog remains fallback. Detection is UNKNOWN outside a few DRC prefixes.
- TD-009 - Token may come from `WC_PAWAPAY_API_TOKEN` (2.4.0); settings field remains as fallback
- TD-010 - HPOS declared compatible (2.4.0)
- TD-011 - Classic checkout only (Blocks UNKNOWN, not claimed)
- TD-012 - Poll GET throttled to 2s/order (2.4.0)
- TD-013 - Tests without Woo bootstrap

## P3 - Low

### TD-014 - UUID via `random_bytes` / `wp_generate_uuid4` (2.4.0)
### TD-015 - French gettext map instead of `.po`
