# Project

## Name
PawaPay Mobile Money Gateway for WooCommerce (`pawapay-woocommerce`).

## Purpose
Generic Woo gateway: API token, callback, countries, operators, charge currencies.

## Status
Production (Maungano live deposits confirmed 2026-09-19). Version 1.2.1.

## Primary users
Woo merchants (Maungano first); shoppers at checkout.

## Core capabilities
v1 deposits, operator picker, currency convert, thank-you poll, admin status check, GitHub updates.

## Repository structure
`wc-pawapay-gateway.php`, `includes/`, `assets/`, `tests/`, `docs/`.

## Backend
PHP 8.0+, WordPress 6+, WooCommerce.

## Frontend
Checkout CSS/JS; thank-you poll JS. Classic checkout.

## Database / storage
Order meta. No plugin tables yet.

## Infrastructure
Merchant WordPress. PUC → GitHub.

## External services
PawaPay sandbox/production.

## Deployment
Copy/activate plugin or wp-admin update from GitHub.

## Environments
Sandbox checkbox + token. Production: sandbox off + live token.

## Critical constraints
See `CONSTRAINTS.md`.

## Out of scope
Native Flutter PawaPay SDK. Payouts/refunds API. Woo Blocks until tested.

## Ownership / maintainers
Maungano / github.com/mmarcwabo/pawapay-woocommerce.
