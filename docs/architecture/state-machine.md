# Payment state machine

## Current mapping (2.2.0)

Trusted `GET /deposits/{id}` (poll, admin, reconciliation, callback lookup) may complete the order. Unsigned callback JSON cannot.

| PawaPay | Woo today | Problem |
|---|---|---|
| *(none yet)* | pending (after place order) | OK |
| ACCEPTED / SUBMITTED / ENQUEUED | pending + note | OK |
| COMPLETED | `payment_complete()` → processing/completed | OK if authentic |
| FAILED / REJECTED | attempt failed; Woo stays pending | Optional setting can still fail the order |
| HTTP timeout / 5xx on initiate | attempt UNKNOWN, Woo pending | Customer is not told it failed |
| Unknown status | attempt UNKNOWN / ignore | Reconcile via GET |

`claim_completion()` is the attempt-level lock. Two workers can still race `payment_complete()` if the attempt is already COMPLETED and the order is unpaid (`complete_order_only`); Woo `needs_payment()` limits a second fulfillment.

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
