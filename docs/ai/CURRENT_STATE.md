# Current State Baseline

> Observed, not desired. Date: 2026-09-19.

## Baseline date
2026-09-19. Working tree **1.4.0** (attempts table, v1 client, initiate lock). Last GitHub release tag may still be **v1.2.1** until this milestone is tagged.

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
Public webhook + REST `/wp-json/pawapay/v1/deposits`. Token in options. Poll AJAX. New initiation notes mask MSISDN; `_pawapay_phone` meta still stores the full number.

## CI/CD status
None in-repo. Updates via GitHub releases.

## Deployment status
Maungano production has completed **live** PawaPay deposits (operator confirmed 2026-09-19). Sandbox deposit 778 completed earlier via poll.

## Documentation status
`README.md` + `CHANGELOG.md` + this `docs/architecture/*` set. Older `docs/ai/*` placeholders filled from this audit.

## Major known risks
Unsigned webhook can complete an order if `depositId` is guessed/leaked. Failed deposit still marks Woo `failed` via `Deposit::apply`.

## Unknowns requiring confirmation
Blocks checkout compatibility. Accuracy of MSISDN→MNO on DRC prefixes. Whether live Wordfence allows PawaPay production callback IPs.
