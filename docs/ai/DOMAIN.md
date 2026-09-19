# Domain

## Business domain
WooCommerce Mobile Money collection via PawaPay deposits for Maungano (and any other Woo shop using this plugin).

## Business objective
Customer pays an order from a mobile-money wallet. Shop fulfills after Woo marks the order paid.

## Actors
Shopper, shop admin, PawaPay platform, MNO (Airtel/Orange/Vodacom/…).

## Core entities / concepts
Woo **Order**. PawaPay **Deposit**. (Target) **PaymentAttempt** linking one deposit to one order.

## Core workflows
Checkout → deposit ACCEPTED → phone PIN → COMPLETED → Woo processing.

## Business rules
Amount comes from the order. One Woo order should not be paid twice. A deposit is one attempt.

## Vocabulary / glossary
Sandbox vs production tokens/dashboards. `En cours` = Woo processing (paid). ACCEPTED ≠ paid.

## Sensitive operations
Token storage, webhook, MSISDN, completion.

## External business systems
PawaPay Merchant API v1. Optional Aelia/WOOCS.

## Regulatory / contractual constraints
PawaPay boarding per country/operator. Production callback IPs. No PIN/OTP collection.
