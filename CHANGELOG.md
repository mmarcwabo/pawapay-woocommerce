# Changelog

## 1.2.0

- Checkout payment box follows PawaPay hosted UI: amount, prefixed phone, operator tiles, powered-by footer.
- Multi-item carts list every line (qty × name + line total) instead of only the first product.

## 1.1.0

- Generic WooCommerce setup: API token, deposit callback, countries, and operators.
- Multi-country PawaPay provider catalog; DRC legacy codes still map correctly.
- Operator picker survives WooCommerce `updated_checkout` (capture-phase clicks, jQuery checkout events, persisted selection, `button type="button"`).
- Charge-currency select: intersection of shop/switcher, plugin-enabled, and operator currencies.
- Converts the deposit amount when the charge currency differs from the order (Aelia, WOOCS, `woocommerce_pawapay_convert_amount`, or manual rates).
- Thank-you page polling as a webhook fallback.
- Shared deposit status application.
- Customer strings follow the site locale (French mapping included).

## 1.0.1

- Sanitize `statementDescription` to PawaPay v1 `[A-Za-z0-9 ]{4,22}` (fixes `PARAMETER_INVALID` from `Order #id`).
- Default DRC providers: `AIRTEL_COD`, `ORANGE_COD`, `VODACOM_MPESA_COD`.
- Call `/active-conf` instead of `/active-configuration`.
- Send `customerTimestamp` as RFC3339 UTC (`…Z`).
- GitHub update checker for `mmarcwabo/pawapay-woocommerce`.
- Show rejection code and message on checkout failure.
- Stop logging raw webhook bodies.

## 1.0.0

- Initial gateway.
