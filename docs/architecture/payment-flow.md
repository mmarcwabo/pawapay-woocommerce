# Payment flow

Observed on plugin **2.2.0**. Live Maungano deposits completed on the 1.2.1 path; 1.3–2.2 add attempts, a v1 client, initiate lock, GET-before-complete, a waiting screen, and background reconciliation.

## Current flow (as implemented)

```mermaid
sequenceDiagram
    participant C as Customer
    participant Woo as WooCommerce
    participant G as WC_PawaPay_Gateway
    participant P as PawaPay v1
    participant W as Webhook / poll

    C->>Woo: Checkout, pick Mobile Money
    Woo->>G: process_payment(order_id)
    G->>G: amount = order.total (server)
    G->>G: currency = POST ∩ operator ∩ settings
    G->>G: PaymentService lock + freeze order amount
    G->>G: overwrite _pawapay_deposit_id
    G->>G: insert attempt row (INITIATING)
    G->>P: POST /deposits
    alt ACCEPTED
        G->>Woo: pending + empty cart
        G->>C: redirect order-received
        C->>P: Approve on phone
        P-->>W: POST callback (hint)
        W->>P: GET /deposits/{id}
        C->>W: AJAX poll GET /deposits/{id}
        Note over W: Action Scheduler also GETs stale attempts
        W->>Woo: payment_complete() if COMPLETED + amounts match
    else timeout / connection
        G->>G: attempt UNKNOWN, order stays pending
        G->>C: thank-you / wait (do not pay again)
    else REJECTED
        G->>C: checkout notice, order still unpaid, retry allowed
    end
```

## What is already correct

- Woo creates the order before the deposit.
- Payable **amount** comes from `$order->get_total()`, not from JavaScript.
- Charge **currency** is chosen at checkout but converted on the server.
- Browser reload of thank-you does not mark paid by itself; JS only polls, then the server calls PawaPay.
- Waiting card is dedicated copy; poll JSON is a sanitized DTO.
- `payment_complete()` is the only path that moves a pending order to paid.

## What is not yet the target

- `_pawapay_deposit_id` is still a latest-deposit pointer. A retry overwrites that meta key; earlier deposits remain in the attempts table.
- Full RFC 9421 ECDSA callback signatures are not verified yet.
- Poll is adaptive but not rate-limited (Phase 9).
- Reconciliation stops after 48 hours; admin “Check PawaPay status” remains for older rows.

## Target flow

See `docs/implementation-plan.md` Phase 3–6. Browser stays non-authoritative. Paid state only after verified callback **or** authenticated `GET /deposits/{id}` **or** background reconciliation.
