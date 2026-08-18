# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/i17-a-remove-the-obs--765`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-765-remove-gateway-e2e-facade`
- Branch: `solo-765-remove-gateway-e2e-facade`

## Goal

The gateway no longer advertises a second, non-functional E2E ownership boundary: the dead gateway composer E2E scripts (test:e2e*/e2e:* pointing at gateway artisan commands that do not exist), the empty gateway PHPUnit E2E testsuite, and its dangling bootstrap branches are removed — while the live external apps/e2e harness (root composer → bin/orbit-e2e-artisan), the App\E2E\Support composer remap, and the focused gateway E2E support that remains live are all preserved. Command discovery, root/apps/e2e ownership, and the gateway unit-suite bootstrap stay green.

## Scope

- Owned: apps/gateway/composer.json (remove the dead E2E script block test:e2e/test:e2e:docker/test:e2e:docker:canary/test:e2e:incus/test:e2e:topology-contract/test:e2e:provision/e2e:preflight/e2e:reap-*/e2e:prepare-* that invoke non-existent gateway artisan commands or the empty E2E suite; keep test and test:slow), apps/gateway/phpunit.xml (remove the empty `E2E` testsuite pointing at tests/E2E), apps/gateway/tests/Pest.php (remove the ->group('e2e')->in('E2E') grouping and the dangling orbitE2eRequiresEnvironment reference to the non-existent tests/E2E/Ephemeral/AgentNodeProvisioningTest.php; keep the ORBIT_E2E handling that live support still uses and the tests/E2E/Support require), and updating apps/gateway/tests/Feature/Architecture/GatewayComposerBoundaryTest.php to assert the dead script keys are ABSENT.
- Constraints: PRESERVE the live external harness and support — composer.json:57 App\E2E\Support remap, autoload-dev tests/Helpers/E2EEnvironment.php, the 6 gateway-owned App\E2E\Support classes (E2ENetwork/E2ENodeProbe/E2EReachability/IncusProvider/ProviderPool/ProviderSelection), all apps/gateway/tests/Feature/E2ESupport/*, and apps/gateway/tests/E2E/Support/{Pest.php,SqliteDatabaseFixture.php} (tested live by E2EPestHelpersTest — MINIMAL PATH: keep tests/E2E/Support + the Pest.php require, only drop the phpunit E2E suite + ->in('E2E') grouping, do NOT delete tests/E2E/ wholesale). Do NOT touch RootGatewayForwardingShimTest / GatewayAppRelocationTest (they assert the live root path). declare(strict_types=1); Mago/Rector clean. NEVER run composer test:e2e* / human-only E2E lanes.
- Out of scope: the external apps/e2e harness itself, the 6 reserved gateway support classes' relocation (later batches), and any root composer changes.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact` 6924 passed, 6 skipped
  - broader: passed - `composer quality-check` on the clean candidate 208409f exit 0, all 45 subgates zero, git.dirty false, receipt `.orbit/quality-gates/quality-check-2026-08-18T010705Z-aefd16c303ee.json`
  - runtime: passed - candidate=208409f16517fb9556453d602842eb1ce7d7815c; venue=retained-incus; environment=dev-fixture; expected=removing the dead gateway E2E facade keeps the deployed gateway booting with an intact command surface and leaves the preserved E2E support working, with GatewayComposerBoundaryTest asserting the dead script keys are absent; observed=Part A topology dev-b06040 booted operator/gateway/dev with gateway API ready and WireGuard gateway 10.6.0.2 / dev 10.6.0.4 + Part B 68 tests (692 assertions) passed inside the operator VM runtime including GatewayComposerBoundaryTest asserting dead-script absence, VerificationScriptsTest, and E2EPestHelpersTest confirming the preserved tests/E2E/Support helpers still load; result=passed; command=`ssh beast incus exec orbit-e2e-dev-b06040-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/gateway && php artisan test tests/Feature/Architecture/GatewayComposerBoundaryTest.php tests/Feature/E2ESupport/VerificationScriptsTest.php tests/Feature/E2ESupport/E2EPestHelpersTest.php'`; evidence=`.orbit/evidence/solo-765-retained-incus-proof.md`
- Blast radius: complete - evidence=Explore facade sweep + diff review of the gateway composer/phpunit/Pest facade surface and its asserting tests; result=dead gateway E2E facade (composer test:e2e*/e2e:* scripts, empty phpunit E2E suite, Pest ->in('E2E') grouping + dangling AgentNodeProvisioningTest reference) removed while the App\E2E\Support remap, 6 reserved support classes, Feature/E2ESupport tests, and tests/E2E/Support helpers are preserved; see `.orbit/evidence/solo-765-blast-radius-inventory.md`
- Review: passed - human-judgment=not-required; independent Claude reviewer VERDICT PASS (all 15 dead test:e2e*/e2e:* scripts removed with no gateway artisan e2e: command existing; empty phpunit E2E suite + Pest ->in('E2E')/orbitE2eRequiresEnvironment removed; App\E2E\Support remap + reserved classes + Feature/E2ESupport + tests/E2E/Support preserved; GatewayComposerBoundaryTest asserts ->not->toHaveKeys on the dead keys; remaining bin/ test:e2e* refs are the human-only lane guards; quality 208409f 45/45 zero; retained-incus dev-b06040 Part A+B verified)
- Reviewed feature tip: 208409f16517fb9556453d602842eb1ce7d7815c
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 208409f16517fb9556453d602842eb1ce7d7815c
- Accepted main tip: 694f024f158b85936fd5140ff50e6fdbd1a24954

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
concrete reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
For stateful, lifecycle, or concrete UX work, optionally append one compact
clause on the existing Scope `Owned` row (do not add a permanent new row):
`primitive=exact requested primitive; transitions=success:terminal success|failure:terminal failure|retry:retry behavior|stop-restart:stop or restart|stale:stale-state or n/a`.
Omit the clause for ordinary/local changes. When `primitive=` or `transitions=`
is present, deterministic lint requires both fields, the five known transition
keys without duplicates or empty values, and rejects template placeholders; it
does not grade prose or decide whether the feature is stateful. Explicit `n/a`
values are fine when a transition does not apply. After FRAME, run
`bin/orbit-feature-acceptance route` for the read-only
diff-derived venue before expensive PROVE work. For non-`automated` venues,
`Verification.runtime: passed` must use one candidate-bound structured receipt
on that same single line. Required fields are candidate=, venue=, environment=,
expected=, observed=, result=passed, and evidence= as one exact inline-code path
under the worktree evidence or quality-gates trees. Use exactly one of target= or command=.
Live/production claims require exact environment=live; ordinary retained
topology may use environment=dev-fixture. Semicolons separate fields,
so values must not embed raw semicolon-delimited pseudo-fields. Known keys
only. Example evidence citation: write a real receipt and cite one exact regular
file below the worktree evidence tree (not a directory root). A failed,
excluded, still-required, or deferred final hop cannot be recorded as passed;
remain in PROVE, disarm any armed or recorded acceptance, and follow FIX ->
BUILD -> PROVE before ACCEPT. Keep a still-valid Review and Reviewed feature tip
on proof-only retries; a HEAD change still needs a refreshed review. Automated
venues keep `runtime: not applicable`. Proof files retained by the compact
archive must be cited as one exact inline-code path; prose, directories, padded
code spans, and partial paths are not proof citations.
