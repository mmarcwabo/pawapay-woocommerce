# Changelog

## 1.0.1

- Sanitize `statementDescription` to PawaPay v1 `[A-Za-z0-9 ]{4,22}` (fixes `PARAMETER_INVALID` from `Order #id`).
- Default DRC providers: `AIRTEL_COD`, `ORANGE_COD`, `VODACOM_MPESA_COD`.
- Call `/active-conf` instead of `/active-configuration`.
- Send `customerTimestamp` as RFC3339 UTC (`…Z`).
- GitHub update checker for `mmarcwabo/pawapay-woocommerce`.
- Show rejection code and message on checkout failure.
- Stop logging raw webhook bodies.

## 1.0.0

- Initial gateway used on maungano.com.
