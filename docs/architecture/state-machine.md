# Payment state machine

## Current mapping (implicit)

PawaPay v1 deposit status is applied almost directly onto the Woo order.

| PawaPay | Woo today | Problem |
|---|---|---|
| *(none yet)* | pending (after place order) | OK |
| ACCEPTED / SUBMITTED / ENQUEUED | pending + note | OK |
| COMPLETED | `payment_complete()` → processing/completed | OK if authentic |
| FAILED / REJECTED | **order `failed`** | Blocks retry on the same order |
| HTTP timeout / 5xx on initiate | checkout failure; order may exist unpaid | May look like “failed” to the shopper |
| Unknown status | note + log | No UNKNOWN attempt state |

`WC_PawaPay_Deposit::apply()` returns immediately if Woo is already `processing`, `completed`, `failed`, `cancelled`, or `refunded`. That is **not** a lock: two workers can both see `pending` and both call `payment_complete()`.

## Target internal attempt states

`CREATED` → `INITIATING` → `ACCEPTED` | `UNKNOWN` → `PROCESSING` → `COMPLETED` | `FAILED` | `EXPIRED` | `CANCELLED`

Woo order stays `pending` until a **COMPLETED** attempt is accepted.

`FAILED` / `EXPIRED` / `CANCELLED` apply to the **attempt**, not automatically to the order. The order stays payable (`pending`) until hold-stock / unpaid-order cancel (Woo settings).

`UNKNOWN` = network uncertainty after initiate or during poll. Never tell the customer the payment failed. Reconcile.

## Completion guards (all required)

1. Attempt exists and deposit_id matches.
2. Attempt is not already `COMPLETED`.
3. Order payment method is `pawapay`.
4. Order not already paid (plus atomic attempt transition).
5. Frozen payment amount/currency match PawaPay `requestedAmount` / `currency` (document sandbox `AMOUNT_DISCREPANCY` as exception: record it, still complete if PawaPay says COMPLETED and requested matches the attempt).
6. Status is final COMPLETED from a trusted source.

## Idempotency

Second COMPLETED (webhook + poll + cron + admin) must no-op after the first successful attempt transition.
