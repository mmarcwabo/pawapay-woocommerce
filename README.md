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
3. In the [PawaPay Dashboard](https://docs.pawapay.io/dashboard/other/system_conf/callback_urls) set **Deposits** callback to the URL shown in the gateway settings:
   `https://YOUR-SITE/pawapay-webhook/`
   Leave Checkouts / Payouts / Refunds empty unless you add those flows later.
4. Settings → Permalinks → Save if the webhook 404s.

If this GitHub repo is **private**, add a read token in `wp-config.php`:

```php
define( 'WC_PAWAPAY_GITHUB_TOKEN', 'github_pat_...' );
```

## How payment completes

1. Checkout sends `POST /deposits`.
2. On `ACCEPTED`, Woo marks the order **pending** and shows the thank-you page.
3. PawaPay should POST the final status to `/pawapay-webhook/`.
4. If the callback is slow or blocked, the thank-you page polls `GET /deposits/{id}` and updates the order.

The thank-you URL is not “paid”. **Payer / Annuler** means the deposit is still pending.

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
```

Requires PHP 8.0+ and WooCommerce.
