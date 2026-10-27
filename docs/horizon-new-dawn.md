# Horizon New Dawn upstream parity

Horizon New Dawn uses its own private project extension. It does not enter
`dependency-routine` or `dependency-package`. The extension ships in this repo,
like the ecosystem extensions. Registry publication needs Nick's approval.

`horizon-sync.json` in `nckrtl/horizon-new-dawn` is the parity ledger. Its merged
baseline is `v5.50.1`, with 70 covered items: 21 routes, 21 controller actions,
16 screens and 12 config keys. PRs #5, #7 and #8 delivered detection, the
one-time baseline and readable ledger fields. The baseline is already done;
normal detection must not create another baseline task. A checkout without a
ledger needs the baseline task before any release work.

Detection runs on the group's enrolled VM worker, against its checkout. It
reads the ledger, lists stable upstream `vX.Y.Z` tags and compares only the next
tag. It compares route statements, public controller actions, Vue screens and
config keys as text. It never boots upstream PHP. Output matches
`scripts/horizon-sync detect`: one candidate per changed item, or one `release`
candidate if none changed. Being current yields no candidates. Read or fetch
errors fail detection; they never mean there are no changes.

Each candidate becomes one ordered task with its upstream identity and a test
requirement. All candidates for a tag must finish before advancing `synced_tag`.
Only the last task advances that tag; each task updates `covered` for its own
change. A release task verifies compatibility before advancing the ledger.
The existing official `@swamp/software-factory` and Orbit Gateway own task
execution, checks and publication. Pull requests wait for Nick to review and
merge. No generic dependency flow, separate factory engine or automatic merge
is added.

The detection workflow has no active schedule. Deploy from Nick's Mac after
merge with `bin/deploy-services`; serve must restart to load extension code.
Starting live Horizon tasks requires the orchestrator's explicit direction.
`scripts/horizon-sync` stays in the project until phase 5.

## Detection evidence

Fixtures retain the source snapshots from Horizon New Dawn's detector tests:
`v5.11.0` → `v5.12.0` (six changes across all four surfaces), `v5.42.0` →
`v5.43.0` (watch config), `v5.49.0` → `v5.50.0` (two screens), and `v5.50.0` →
`v5.50.1` (release only). Tests build local Git tags from these snapshots and
compare exact JSON output. No network or running Horizon service is required.

## Manual entry point

The `@nckrtl/project-horizon-new-dawn-detect` workflow takes `group`, `worker` and
`cwd`. Project 57 comes from config. `group` must already be an open external package group with an
enrolled worker. The workflow checks its existing enrollment receipt; it does
not mint tokens, start workers or change placement. The worker job uses that
exact name. The server job reads that run's `plan` resource through CEL and
calls the Gateway's `horizon_tasks` extension method.

Task creation produces an external **backlog** group containing one child per
candidate, in order. No factory or agent starts. A repeated detection reuses
its saved group receipt. A lost create reply is reconciled by the exact brief;
an uncertain absent or duplicate group requires the orchestrator to reconcile.
The Gateway group limit of 50 tasks fails explicitly instead of dropping items.

Project 57 `horizon-new-dawn` points to `nckrtl/horizon-new-dawn`, with
root `.`, branch `main`, and check
`composer format:test && composer analyse && composer test`. Project 27
`horizon-new-dawn-website` points to `nckrtl/horizon-new-dawn-website`. Both admission and worker validation reject
Project 27. Project 57 has review and merge off so that parity PRs wait for Nick.
Compatibility tests also need an isolated local Redis and the exact selected
Horizon version installed in a temporary test environment.
