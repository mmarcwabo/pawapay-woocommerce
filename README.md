# PawaPay Mobile Money Gateway for WooCommerce

WooCommerce payment gateway used by [Maungano](https://maungano.com) to collect Mobile Money via [PawaPay](https://docs.pawapay.io/v2/docs/how_to_start) (API **v1** deposits).

Repo: [github.com/mmarcwabo/pawapay-woocommerce](https://github.com/mmarcwabo/pawapay-woocommerce)

## What this plugin does

- Checkout fields: phone + Airtel / Orange / Vodacom
- `POST /deposits` to sandbox or production
- Webhook at `/pawapay-webhook/` for the final `COMPLETED` / `FAILED` status
- wp-admin update notices from this GitHub repo (via [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker))

API tokens stay in WooCommerce settings. Do not commit them.

## Install on WordPress (first time)

1. Copy this folder to `wp-content/plugins/pawapay-woocommerce/` (folder name must stay `pawapay-woocommerce`).
2. Plugins → activate **PawaPay Mobile Money Gateway for WooCommerce**.
3. WooCommerce → Settings → Payments → PawaPay:
   - Enable
   - Sandbox on for tests
   - Paste the **sandbox** API token
   - Currency matching the MNO (USD or CDF)
   - MNOs (defaults are the official DRC codes):
     ```
     Airtel Money|AIRTEL_COD
     Orange Money|ORANGE_COD
     Vodacom M-Pesa|VODACOM_MPESA_COD
     ```
4. PawaPay Dashboard → Callback URLs → **Deposits only**:
   `https://YOUR-SITE/pawapay-webhook/`
5. Settings → Permalinks → Save (if the webhook 404s).

If the GitHub repo is **private**, add to `wp-config.php`:

```php
define( 'WC_PAWAPAY_GITHUB_TOKEN', 'ghp_...' );
```

Use a fine-scoped token with `repo` read (or Contents read on that repository). Public repos need no token.

## How WordPress detects updates

The plugin compares its header `Version:` to `main` on GitHub.

To ship a fix:

1. Bump `Version:` and `WC_PAWAPAY_VERSION` (same number).
2. Commit and push `main`.
3. Create a GitHub Release tagged `vX.Y.Z` matching that version (recommended).
4. On the site: Dashboard → Updates, or wait for the next WP cron check.

WordPress will not see GitHub by itself until **this 1.0.1 build** (with the updater) is installed once.

## Sandbox test (DRC)

Use [PawaPay test numbers](https://docs.pawapay.io/v2/docs/test_numbers). There is no PIN prompt in sandbox.

| Operator | Phone | Expected |
|---|---|---|
| Orange | `243893456789` | COMPLETED |
| Airtel | `243973456789` | COMPLETED |
| Vodacom | `243813456789` | COMPLETED |

## Changelog highlights (1.0.1)

- `statementDescription` is alphanumeric only (`Order 123`, not `Order #123`) — fixes `PARAMETER_INVALID`
- DRC provider codes: `AIRTEL_COD`, `VODACOM_MPESA_COD`
- Active configuration path: `/active-conf`
- RFC3339 `Z` timestamps
- GitHub update checker
- Webhook logs deposit id/status, not the raw body

## Development

```bash
composer install --no-dev
php tests/sanitize-statement-test.php
```

Requires PHP 8.0+ and WooCommerce.
