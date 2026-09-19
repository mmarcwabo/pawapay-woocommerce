# Architecture

## Architecture status
Observed 2026-09-19 on plugin **2.3.0**. Attempts, verified completion, waiting UX, reconciliation, admin attempts list, and cached `/active-conf` are live.

## Current architecture
Classic WooCommerce payment gateway. `WC_PawaPay_Gateway` owns checkout fields and settings. `WC_PawaPay_Payment_Service` initiates deposits. `WC_PawaPay_Callback_Processor` confirms callbacks with `GET /deposits/{id}`. `WC_PawaPay_Reconciler` GETs stale attempts via Action Scheduler. `WC_PawaPay_Deposit::apply()` completes only from trusted sources after amount checks. RFC 9421 ECDSA verify not claimed.

## High-level component map
`wc-pawapay-gateway.php` boots storage/migrator, providers, currency, API, deposit, gateway, webhook, thankyou, i18n, PUC.

Checkout JS: operator cards + phone compose. Thank-you / order-pay JS: adaptive poll, customer-safe DTO.

## Modules / bounded areas
| Area | Class / files |
|---|---|
| Woo adapter | `class-wc-pawapay-gateway.php` |
| Initiation | `class-wc-pawapay-payment-service.php`, policy, lock |
| HTTP | `WC_PawaPay_Client` + `class-wc-pawapay-api.php` (v1 `POST /deposits`) |
| Catalog | `class-wc-pawapay-providers.php`, `class-wc-pawapay-catalog.php` (cached `/active-conf`) |
| Admin | `class-wc-pawapay-admin.php`, `class-wc-pawapay-admin-attempt-view.php` |
| FX | `class-wc-pawapay-currency.php` |
| Sync | `class-wc-pawapay-deposit.php` |
| Attempts | `class-wc-pawapay-attempt.php`, repository, migrator |
| Ingress | `class-wc-pawapay-webhook.php` (rewrite + REST) |
| UX | `class-wc-pawapay-thankyou.php`, `class-wc-pawapay-poll-policy.php`, `assets/*` |
| Reconcile | `class-wc-pawapay-reconciliation-policy.php`, `class-wc-pawapay-reconciler.php` |

## Data architecture
`{prefix}pawapay_transactions` plus 1.x `_pawapay_*` meta. Last deposit still wins on meta. See `docs/architecture/payment-attempts.md`.

## Authentication
Merchant Bearer token to PawaPay. Public callback is unauthenticated; status is confirmed with a token GET.

## Authorization
Poll: order key + nonce. Admin: `manage_woocommerce` attempts page + metabox + nonce GET.

## External integrations
PawaPay Merchant API v1 (`/deposits`, `/deposits/{id}`, cached `/active-conf`). Optional Aelia/WOOCS filters.

## Async jobs / queues / schedulers
Action Scheduler recurring `wc_pawapay_reconcile` (5 min). Poll + admin action remain.

## Runtime / deployment
WordPress plugin; GitHub PUC on `main`. Maungano: `/var/www/maungano.com/wp-content/plugins/pawapay-woocommerce`.

## Observability
`wc_get_logger()` source `wc-pawapay`. Debug can log redacted payloads.

## Known architectural compromises
Gateway still large. RFC 9421 ECDSA not claimed. Reconciliation stops after 48 hours.
