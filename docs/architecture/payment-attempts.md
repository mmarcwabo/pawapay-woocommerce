# Payment attempts

## Current storage (1.2.1)

No attempts table. Latest values overwrite order meta:

| Meta | Meaning |
|---|---|
| `_pawapay_deposit_id` | Last UUID sent to PawaPay |
| `_pawapay_phone` | Full MSISDN |
| `_pawapay_mno` | Provider code |
| `_pawapay_currency` | Charge currency |
| `_pawapay_amount` | Formatted charge amount |
| `_pawapay_requested_amount` | From last COMPLETED payload |
| `_pawapay_deposited_amount` | From last COMPLETED payload |
| `_pawapay_amount_discrepancy` | `yes` if requested ≠ deposited |

`find_order()` looks up `_pawapay_deposit_id`. A second `process_payment` on the same order (if Woo ever re-enters it) **loses** the previous deposit id. Historical attempts exist only as order notes.

## Target model

Table `{prefix}pawapay_transactions` (HPOS-safe; not `wp_posts`).

Recommended columns: `id`, `order_id`, `deposit_id` (UNIQUE), `provider`, `msisdn_hash`, `masked_msisdn`, `order_currency`, `order_amount`, `payment_currency`, `payment_amount`, `exchange_rate`, `status`, `provider_transaction_id`, `failure_code`, `failure_message`, `created_at`, `updated_at`, `completed_at`.

Do **not** store full MSISDN or raw API secrets. Store hashes for lookup if needed.

Indexes: `UNIQUE(deposit_id)`, `INDEX(order_id, status)`, `INDEX(created_at)`.

Schema version in `wp_options` (`wc_pawapay_schema_version`). `dbDelta` on activate / `plugins_loaded` upgrade.

## Migration

1. Keep reading `_pawapay_deposit_id` for old orders (1.x).
2. On first sync or first new attempt after upgrade, optionally backfill one row from existing meta.
3. New checkouts write a row **before** `POST /deposits` and never overwrite a prior row.
4. Do not delete 1.x meta.

## Retry

Retry = new row, new `deposit_id`, same `order_id`. Woo order stays one order. Unpaid-order / My Account → Pay uses the same order.
