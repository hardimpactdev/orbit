# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none (live RCA lived under project 77 codex-live-log-command-candidate; not a proof citation for this loop)
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-fix-live-gateway-migration-memory`
- Branch: `codex/fix-live-gateway-migration-memory`

## Goal

Gateway candidate fleet migrations stay memory-bounded on large `activity_log` datasets and the update runner invokes migrations with an explicit PHP memory ceiling, so cutover migration `2026_08_05_120000_rename_app_instances_to_instances_and_project_permissions_to_app` no longer OOMs at the live 128 MiB CLI default (candidate `20260805T162728Z-3da61146f`, activity 453501 / operation run `262b355e-4cca-43e6-aedf-cc9bc40fa77b`).

## Scope

- Owned: `apps/gateway/database/migrations/2026_08_05_120000_rename_app_instances_to_instances_and_project_permissions_to_app.php`; `apps/gateway/app/Services/Gateway/GatewaySwarmManager.php` (`runGatewayMigrations`); focused Pest under `apps/gateway/tests/Feature/Migrations/` and `apps/gateway/tests/Unit/Services/Gateway/GatewaySwarmManagerTest.php` plus updater Process fakes; runtime receipt cited under Proof only. primitive=gateway-fleet-migration; transitions=success:migrations-complete-memory-bounded|failure:migration-fails-without-unbounded-activity-log-load|retry:safe-rerun-from-pre-or-idempotent-partial-state|stop-restart:n/a|stale:n/a
- Constraints: TDD red-then-green; no new migration merely to avoid fixing the failed cutover; no live topology / no SSH to standing live fleet / no composer test:e2e*; no VERSION/release/manifest/live state; merge main only (no rebase); preserve unrelated worktree state.
- Out of scope: live release worktree/project 77 and failed candidate retry; standing live fleet; unrelated historical migrations; ACCEPT/LAND/self-review/push/RC build.

## Proof

- Verification:
  - focused: passed - RewriteActivityLogWorkloadPropertiesTest + GatewaySwarmManager memory ceiling + Rename/MigrateAppInstance + GatewayServiceUpdater/FleetUpdateVerifier process fakes (24+ related green after red)
  - broader: passed - `composer quality-check` clean at de52c7876; `composer quality-gate:final-check` evidence-only (timing warnings only); `bin/orbit-secret-scan` PASS
  - runtime: passed - candidate=de52c78766a3556a1eb7d29fc6e4aea08bfad25b; venue=retained-incus; environment=dev-fixture; command=php -d memory_limit=32M .orbit-proof/proof-run.php && php -d memory_limit=32M .orbit-proof/proof-rerun.php on topology dev-0c58bf gateway orbit-run; expected=20000 activity_log rows rewritten under 32M with correct tokens and safe re-run; observed=passed under 32M peak 30MiB all tokens rewritten re-run ok; result=passed; evidence=`.orbit/evidence/gateway-migration-memory/08-runtime-receipt.json`
- Blast radius: not-required - evidence=repo search of runGatewayMigrations / migrate command assembly + process fakes; only GatewaySwarmManager production path and its two fleet Process-fake helpers needed the argv change and both are updated; result=local hardening of existing update/migration contract
- Review: passed - human-judgment=not-required - CHECKOUT_PROOF cwd=/Users/nckrtl/orbit/.worktrees/codex-fix-live-gateway-migration-memory branch=codex/fix-live-gateway-migration-memory head=de52c78766a3556a1eb7d29fc6e4aea08bfad25b base=bb9e79e35510f3cb437aec9d7a0057b2dcd37703 status=clean; FINDINGS: none; VERDICT: PASS
- Reviewed feature tip: de52c78766a3556a1eb7d29fc6e4aea08bfad25b
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: de52c78766a3556a1eb7d29fc6e4aea08bfad25b
- Accepted main tip: bb9e79e35510f3cb437aec9d7a0057b2dcd37703

## Status

- State: accepted
- Blocker: none

## Docs decision

No product docs edit: `apps/docs/content/domains/11_operation/2_update-all/technical/1_update-all.md` already requires gateway migrations through the target gateway image before replacement and recovery on migration failure. Chunked activity_log rewriting and the fleet PHP memory ceiling are implementation hardening of that existing contract, not a product behavior change.

## Feedback

- Events: `.orbit/feedback.jsonl`
