# Constraints

## Hard technical constraints
PawaPay v1 `statementDescription` `^[A-Za-z0-9 ]{4,22}$`. Live Maungano path is v1 `POST /deposits`, not `/v2/deposits`.

## Compatibility constraints
Keep gateway id `pawapay`. Keep 1.x order meta readable. PHP 8.0+. HPOS via Woo APIs only.

## Hosting / infrastructure constraints
Pretty permalinks required for `/pawapay-webhook/`. REST fallback exists. Firewalls must allow PawaPay callback IPs for webhooks.

## Budget / operational constraints
No extra paid PSP in this plugin. Debug must be off in steady production.

## Security constraints
Do not enable signed outbound financial requests until the client signs. Do not localize the API token. Prefer `WC_PAWAPAY_API_TOKEN` over the settings field.

## Legal / regulatory constraints
UNKNOWN beyond PawaPay merchant contract and DRC operators boarded on the live account.

## Data constraints
Existing orders have a single `_pawapay_deposit_id`. Do not drop it.

## Delivery constraints
Do not break the working live checkout in one unreviewed rewrite.

## Explicitly forbidden changes
Unrelated Maungano app/WordPress core refactors. Committing tokens. Force-push of `main`.
