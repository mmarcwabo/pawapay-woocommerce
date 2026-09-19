# Technical Debt

## P0 - Critical

### TD-001 - Unsigned webhook completes orders
- Evidence: `WC_PawaPay_Webhook::apply_payload` trusts JSON `status`; REST `permission_callback` is `__return_true`.
- Impact: Forged COMPLETED can pay an order if deposit id is known.
- Status: Open. Target: Phase 4.

### TD-002 - One deposit id per order
- Evidence: `process_payment` overwrites `_pawapay_deposit_id`.
- Impact: Lost audit trail; retry can orphan a live wallet hold.
- Status: Open. Target: Phase 1.

## P1 - High

### TD-003 - Failed deposit fails the Woo order
- Evidence: `Deposit::apply` FAILED/REJECTED → `update_status('failed')`.
- Impact: Customer cannot Pay again on the same order.
- Status: Open. Target: Phase 4.

### TD-004 - Non-atomic completion
- Evidence: status check then `payment_complete()` without attempt lock.
- Impact: Duplicate emails/fulfillment under concurrent poll+webhook.
- Status: Open. Target: Phase 4.

### TD-005 - Full MSISDN in order notes
- Evidence: `process_payment` note includes `$phone`.
- Status: Open. Target: Phase 3/9.

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
