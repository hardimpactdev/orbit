candidate=3ca46acf9e3acf6d2892bb695ed526c64879559a

Selected macOS Desktop ownership correction proved.

## Changed files

- apps/gateway/app/Services/Operations/WorkloadNodeUpdater.php
- apps/gateway/tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php
- PRODUCT_DECISIONS.md
- apps/docs/content/domains/11_operation/2_update-all/update-all.md
- apps/docs/content/domains/11_operation/2_update-all/technical/1_update-all.md
- apps/docs/content/domains/11_operation/2_update-all/technical/6.1_update-all_output-render_human.md
- apps/docs/content/domains/11_operation/2_update-all/technical/6.2_update-all_output-render_json.md
- apps/docs/content/domains/1_node/node-concepts.md
- apps/docs/content/tech-stack.md

## Predicate correction

Removed `node.managed` from `WorkloadNodeUpdater::preMutationSkip()` and `desktopArtifactPayload()`. Selected macOS/Darwin workload nodes now use Desktop ownership after fleet selection. Stored `managed=true` remains the roleless selector opt-in. `FleetUpdateTargetSelector` was not broadened.

## RED

Command:

```
bin/orbit-gateway-pest --compact --filter="skips a selected role-bearing macOS node whose Agent is unavailable before mutation|stages a desktop archive and pending automatic handoff for a reachable selected role-bearing Mac"
```

Result: failed, 2 tests, 0 passed.

- `it skips a selected role-bearing macOS node whose Agent is unavailable before mutation`: expected `status=skipped` `reason=orbit_desktop_not_running`; actual `status=completed` `doctor_issues=0`.
- `it stages a desktop archive and pending automatic handoff for a reachable selected role-bearing Mac`: failed asserting array has key `artifact_url` because `desktop_artifact` was null.

## GREEN

- Same filter: 2 passed after the predicate change.
- Related cases (managed skip, managed Desktop staging, Linux Agent-unavailable failure, post-mutation failure, incomplete Desktop identity): 7 passed, 74 assertions.
- Full file: `bin/orbit-gateway-pest --compact tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php` — 34 passed, 333 assertions.
- Reachable-Mac tests that previously skipped Agent readiness now fake HTTP 405 so they still prove completed updates.

## Mago / docs / quality-check

- `bin/orbit-gateway-vendor-bin mago format` and `mago lint` on the two changed PHP files: no issues.
- `composer docs-lint`: passed (warnings only).
- `composer quality-check`: exit 0, dirty=false, commit `3ca46acf9e3acf6d2892bb695ed526c64879559a`, artifact `.orbit/quality-gates/quality-check-2026-08-23T214748Z-c5dc8022a524.json`, duration 222s.

## Remaining runtime proof

Acceptance venue vs `origin/main` is `retained-incus`. Live Mini (Desktop quit → skip) and NMBP (caller-local continue) proof is owner-owned. Do not treat in-memory Pest as runtime passed.

## Risks

- `.orbit/loop.md` Goal was rewritten during this slice to all-active-node selection regardless of roles/`managed`. This candidate implements the assigned selected-macOS Desktop ownership correction only and does not change `FleetUpdateTargetSelector`.
- `bin/orbit-feature-proof-receipt` against local `main` fails with "feature branch contains no changed files" because primary `main` diverged and the exact route then spans `host-macos` plus `retained-incus`. Against `--base=origin/main` the candidate is the 9 files above, venue `retained-incus`.
- Did not merge.
