# Changelog

## 2.0.0

- Callbacks are a hint. An order is paid only after `GET /deposits/{id}` (or another trusted status lookup) confirms `COMPLETED`.
- A forged `COMPLETED` callback can no longer mark an order paid.
- Duplicate `COMPLETED` lookups complete the order once (`claim_completion`).
- Amount and currency must match the frozen payment attempt (`30` equals `30.00`). Sandbox deposited≠requested is recorded, not used as the match.
- A failed PawaPay deposit fails the **attempt**. The WooCommerce order stays pending unless “Mark order failed on failed deposit” is enabled.
- Optional “Require signed callbacks” checks Content-Digest and Signature-Date. Leave it off until PawaPay “Sign all callbacks” is on. Full RFC 9421 ECDSA verification is not claimed yet.

**Upgrade:** 1.x orders keep `_pawapay_deposit_id`. New and old callbacks both confirm via GET. Do not enable signed callbacks in the PawaPay dashboard until this version is live and the plugin setting is on.

## 1.4.0

- Initiate through `WC_PawaPay_Payment_Service`: amount and currency come from the WooCommerce order, not from JavaScript.
- A short lock plus attempt reuse stops a double-click from creating a second live PawaPay deposit.
- If PawaPay times out, the attempt is `UNKNOWN` and the order stays pending. The customer is not told the payment failed.
- Place order disables after the first PawaPay submit. Order notes mask the phone number.

## 1.3.1

- Introduce `WC_PawaPay_Client` so checkout and poll depend on a contract, not a URL version.
- Keep live traffic on Merchant API v1 (`POST /deposits`, `GET /deposits/{id}`). No v2 deposit calls.
- Normalize timeouts, connection errors, 5xx, and invalid JSON. Debug redaction now covers tokens as well as MSISDNs.

## 1.3.0

- Store every PawaPay deposit as a payment attempt (`{prefix}pawapay_transactions`). Retries no longer erase earlier deposit ids.
- Keep writing 1.x order meta (`_pawapay_deposit_id` and related keys) so existing lookups and upgrades keep working.
- Webhook and poll can resolve an order from a historical deposit id, then optionally backfill one attempt from 1.x meta.
- The attempts table stores a hashed and masked MSISDN only.
- WooCommerce completion rules are unchanged in this release (unsigned webhook and `FAILED` → order `failed` remain Phase 4).

## 1.2.1

- Completion notes use `requestedAmount` / `depositedAmount` and record sandbox amount discrepancies.
- Debug logs mask MSISDNs.
- REST callback `/wp-json/pawapay/v1/deposits` if `/pawapay-webhook/` 404s.
- Order action **Check PawaPay status** for pending deposits.
- Thank-you poll explains when it stops; remove the private-repo GitHub token warning.

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
