# Architecture

## Architecture status
Observed 2026-09-19 on plugin **1.4.0**. Attempts table, `WC_PawaPay_Client`, and initiate lock are live; trusted completion (Phase 4) is **not** implemented yet.

## Current architecture
Classic WooCommerce payment gateway. `WC_PawaPay_Gateway` owns checkout fields and settings. `WC_PawaPay_Payment_Service` initiates deposits with a lock and frozen order amounts. Shared `WC_PawaPay_Deposit::apply()` writes Woo status from webhook JSON or from `GET /deposits/{id}`. No Action Scheduler, no signed webhooks.

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
Merchant Bearer token to PawaPay. Webhook has no caller auth.

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
God gateway; unsigned webhook applies status; FAILED fails the Woo order. Attempt history exists from 1.3.0.
