# Current State Baseline

> Observed, not desired. Date: 2026-09-19.

## Baseline date
2026-09-19. Plugin **2.4.0**. Release target **v2.4.0** (covers 1.3.0–2.4.0 since last published tag **v1.2.1**).

## Repository health
Single `main`. Composer: PHP 8.0+, Plugin Update Checker 5.x. No CI in this repo.

## Build status
No compile step. PHP lint + script tests run locally.

## Test status
`tests/*-test.php` (no WordPress bootstrap): statement, providers, currency, deposit extract, attempt repository (in-memory). No Woo/HPOS/webhook signature tests.

## Dependency status
Only PUC in `vendor/`.

## Database status
`{prefix}pawapay_transactions` (schema v1) plus Woo order meta + notes.

## Security-sensitive surfaces
Public webhook + REST `/wp-json/pawapay/v1/deposits`. Token from `WC_PAWAPAY_API_TOKEN` or options. Poll AJAX (throttled). New `_pawapay_phone` meta is masked.

## CI/CD status
None in-repo. Updates via GitHub releases.

## Deployment status
Maungano production has completed **live** PawaPay deposits (operator confirmed 2026-09-19). Sandbox deposit 778 completed earlier via poll.

## Documentation status
`README.md` + `CHANGELOG.md` + this `docs/architecture/*` set. Older `docs/ai/*` placeholders filled from this audit.

## Major known risks
RFC 9421 ECDSA callback signatures are not fully verified. Optional setting can still fail the Woo order on a failed deposit. Blocks checkout is untested.

## Unknowns requiring confirmation
Blocks checkout compatibility. Accuracy of MSISDN→MNO on DRC prefixes. Whether live Wordfence allows PawaPay production callback IPs.
