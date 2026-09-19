# Architecture

## Architecture status
Observed 2026-09-19 on plugin **2.0.0**. Attempts, v1 client, initiate lock, and GET-before-complete are live.

## Current architecture
Classic WooCommerce payment gateway. `WC_PawaPay_Gateway` owns checkout fields and settings. `WC_PawaPay_Payment_Service` initiates deposits. `WC_PawaPay_Callback_Processor` confirms callbacks with `GET /deposits/{id}`. `WC_PawaPay_Deposit::apply()` completes only from trusted sources after amount checks. No Action Scheduler. RFC 9421 ECDSA verify not claimed.

## High-level component map
`wc-pawapay-gateway.php` boots storage/migrator, providers, currency, API, deposit, gateway, webhook, thankyou, i18n, PUC.

Checkout JS: operator cards + phone compose. Thank-you JS: fixed-interval poll.

## Modules / bounded areas
| Area | Class / files |
|---|---|
| Woo adapter | `class-wc-pawapay-gateway.php` |
| Initiation | `class-wc-pawapay-payment-service.php`, policy, lock |
| HTTP | `WC_PawaPay_Client` + `class-wc-pawapay-api.php` (v1 `POST /deposits`) |
| Catalog | `class-wc-pawapay-providers.php` (static) |
| FX | `class-wc-pawapay-currency.php` |
| Sync | `class-wc-pawapay-deposit.php` |
| Attempts | `class-wc-pawapay-attempt.php`, repository, migrator |
| Ingress | `class-wc-pawapay-webhook.php` (rewrite + REST) |
| UX | `class-wc-pawapay-thankyou.php`, `assets/*` |

## Data architecture
`{prefix}pawapay_transactions` plus 1.x `_pawapay_*` meta. Last deposit still wins on meta. See `docs/architecture/payment-attempts.md`.

## Authentication
Merchant Bearer token to PawaPay. Public callback is unauthenticated; status is confirmed with a token GET.

## Authorization
Poll: order key + nonce. Admin sync: Woo shop manager order action.

## External integrations
PawaPay Merchant API v1 (`/deposits`, `/deposits/{id}`, `/active-conf` unused at runtime). Optional Aelia/WOOCS filters.

## Async jobs / queues / schedulers
None. Poll + optional admin action only.

## Runtime / deployment
WordPress plugin; GitHub PUC on `main`. Maungano: `/var/www/maungano.com/wp-content/plugins/pawapay-woocommerce`.

## Observability
`wc_get_logger()` source `wc-pawapay`. Debug can log redacted payloads.

## Known architectural compromises
Gateway still large. RFC 9421 ECDSA not claimed. No Action Scheduler yet.
