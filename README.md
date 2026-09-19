# PawaPay Mobile Money Gateway for WooCommerce

A WooCommerce payment gateway for [PawaPay](https://docs.pawapay.io/v2/docs/how_to_start) **v1 deposits**. It works on any WordPress / WooCommerce site: you only configure API keys, the deposit callback, countries, and mobile-money operators.

Repo: [github.com/mmarcwabo/pawapay-woocommerce](https://github.com/mmarcwabo/pawapay-woocommerce)

## Setup

1. Copy this folder to `wp-content/plugins/pawapay-woocommerce/` and activate the plugin.
2. WooCommerce → Settings → Payments → PawaPay:
   - Enable the method
   - Paste the **sandbox** or **production** API token (they are different)
   - Choose **supported countries**
   - Choose **supported operators** (PawaPay provider codes)
   - Optional: custom `LABEL|PROVIDER_CODE` lines
   - Choose **charge currencies** (shop/switcher currencies plus PawaPay catalog)
   - If customers can pay in a currency the cart is not priced in, add **exchange rates** (`CDF=2800` = 2800 CDF per 1 shop-base unit). Skip this when Aelia Currency Switcher or WOOCS is converting.
3. In the [PawaPay Dashboard](https://docs.pawapay.io/dashboard/other/system_conf/callback_urls) set **Deposits** callback to one of the URLs shown in the gateway settings:
   `https://YOUR-SITE/pawapay-webhook/`
   or `https://YOUR-SITE/wp-json/pawapay/v1/deposits` if the pretty permalink 404s.
   Leave Checkouts / Payouts / Refunds empty unless you add those flows later.
4. Settings → Permalinks → Save if the webhook 404s. Pending orders also have **Check PawaPay status** under order actions.

The official repo is **public**. WordPress can check GitHub for updates without a token. Plugins → **Check for updates** should offer the latest `Version` on `main`.

A token is only needed if you point the checker at a private fork: `WC_PAWAPAY_GITHUB_TOKEN` in `wp-config.php`, or the GitHub update token field in the gateway settings.

## How payment completes

1. Checkout sends `POST /deposits`.
2. On `ACCEPTED`, Woo marks the order **pending** and shows the thank-you page.
3. PawaPay should POST the final status to `/pawapay-webhook/`.
4. If the callback is slow or blocked, the thank-you page polls `GET /deposits/{id}` and updates the order.

The thank-you URL is not “paid” until PawaPay returns `COMPLETED` (webhook or poll). **En cours** in WooCommerce means the deposit is paid and the order is being fulfilled.

From 1.3.0 each `POST /deposits` is also stored as a **payment attempt** row. One WooCommerce order can have several attempts. The latest deposit is still copied onto `_pawapay_deposit_id` so 1.x tools keep working. The table is created on activate and on upgrade (`wc_pawapay_schema_version`).

## Currency picker

Catalog prices stay in WooCommerce (and Aelia/WOOCS if those plugins are active). Checkout adds a **payment currency** select: the intersection of currencies enabled on the site, currencies enabled in this plugin, and currencies the selected operator can collect.

If that charge currency differs from the order, the plugin converts the total before `POST /deposits` using, in order:

1. `woocommerce_pawapay_convert_amount`
2. Aelia `wc_aelia_cs_convert`
3. WOOCS `convert_from_to_currency`
4. The manual `exchange_rates` setting

This matches the usual e-commerce split: a storefront switcher changes displayed prices; the payment method only offers currencies the PSP and wallet can actually settle.

## Sandbox test numbers

Use [PawaPay sandbox MSISDNs](https://docs.pawapay.io/v2/docs/test_numbers). There is no PIN prompt in sandbox.

DRC examples:

| Operator | Phone | Expected |
|---|---|---|
| Orange | `243893456789` | COMPLETED |
| Airtel | `243973456789` | COMPLETED |
| Vodacom | `243813456789` | COMPLETED |

## Updates

Bump `Version:` and `WC_PAWAPAY_VERSION`, push `main`, tag `vX.Y.Z`. WordPress compares the header on `main` via Plugin Update Checker.

## Changelog

### 1.4.0

- Checkout initiation is locked and idempotent. Timeouts stay unconfirmed; retries do not create a second live deposit.

### 1.3.1

- PawaPay HTTP is behind `WC_PawaPay_Client`. Live deposits stay on v1 `/deposits`.

### 1.3.0

- Payment attempts table; retries keep earlier deposit ids; 1.x order meta still written.

### 1.2.1

- REST deposit callback, order-action status sync, redacted debug logs, and no private-repo update notice.

### 1.2.0

- Checkout UI matches PawaPay hosted checkout (amount, country prefix, operator tiles).
- Multi-item carts show a line list under **For**.

### 1.1.0

- Works as a generic WooCommerce gateway: API token, deposit callback, countries, and operators are all settings.
- Built-in PawaPay provider catalog (multi-country) with official DRC codes and legacy aliases.
- Operator cards are real buttons; capture-phase clicks and Woo `updated_checkout` keep Orange / Vodacom selectable after checkout refresh.
- Admin and checkout currency selects: shop ∩ plugin ∩ operator.
- Converts the order total when the charge currency differs (Aelia, WOOCS, filter, or manual rates).
- Thank-you page waits for confirmation and polls PawaPay if the webhook is late.
- Shared deposit status handler for webhooks and polling.
- Customer strings follow the site locale (French mapping included).

### 1.0.1

- `statementDescription` is alphanumeric only (`Order 123`, not `Order #123`) — fixes `PARAMETER_INVALID`.
- DRC provider codes: `AIRTEL_COD`, `VODACOM_MPESA_COD`.
- Active configuration path: `/active-conf`.
- RFC3339 `Z` timestamps.
- GitHub update checker.
- Webhook logs deposit id/status, not the raw body.

### 1.0.0

- Initial deposit gateway.

## Development

```bash
composer install --no-dev
php tests/sanitize-statement-test.php
php tests/providers-test.php
php tests/deposit-status-test.php
php tests/currency-test.php
php tests/attempt-test.php
php tests/client-test.php
php tests/initiate-test.php
```

Requires PHP 8.0+ and WooCommerce.
