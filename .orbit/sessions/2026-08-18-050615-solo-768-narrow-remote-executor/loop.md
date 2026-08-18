# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/i03-b-replace-aggreg--768`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-768-narrow-remote-executor`
- Branch: `solo-768-narrow-remote-executor`

## Goal

The aggregate `RemoteExecutor` interface (which combined RemoteShell + StartsRemoteShellProcesses) no longer forces run-only consumers to advertise an unsupported async `start()`: the sole production aggregate consumer (ProvisioningAgentInstaller) depends on the narrow `RemoteShell`; only the genuinely-capable executors (RemoteHostExecutor, RemoteOrbitGatewayExecutor, SshRemoteShell) declare `StartsRemoteShellProcesses`; `RemoteLocalExecutor` loses its throw-only `start()`/`startInternal()`; the redundant aggregate binding and the speculative `startInternal` are removed; and the ~40 test doubles that repeated a throwing `start()` re-type to the narrow contracts. start() coverage is retained for the actually-capable executors.

## Scope

- Owned (all apps/gateway): Contracts — remove the aggregate `app/Services/RemoteShell/RemoteExecutor.php` (keep RemoteShell + StartsRemoteShellProcesses); make the 3 capable executors implement `RemoteShell, StartsRemoteShellProcesses` directly and `RemoteLocalExecutor` implement `RemoteShell, RunsInternalCommands` (delete its throw-only start()/startInternal() + the START_UNSUPPORTED_MESSAGE constant + the asserting test); narrow `app/Services/Operations/ProvisioningAgentInstaller.php` transport type to `RemoteShell`; drop the redundant `bind(RemoteExecutor::class, ...)` in `app/Providers/AppServiceProvider.php` (keep the RemoteShell/StartsRemoteShellProcesses/RunsInternalCommands binds) and the `TestCase.php` aggregate bind; re-type the ~40 test doubles/fakes that `implements RemoteExecutor` (+ inline throwing start() fakes) to `RemoteShell` (+ RunsInternalCommands where they use runInternal), dropping the dead throwing start() stubs; update/simplify `RemoteExecutorContractTest`.
- Constraints: PRESERVE start() for the genuinely-capable executors (RemoteHostExecutor, RemoteOrbitGatewayExecutor, SshRemoteShell) + their StartsRemoteShellProcesses binding; force/changed-source semantics unchanged; prove NO aggregate `RemoteExecutor` or `startInternal` references remain (rg sweep). External container consumers: grep confirmed zero refs outside apps/gateway (apps/cli/reverb/agent, packages/* clean). Broad compile-time test blast radius (38 files ref RemoteExecutor) — run the full gateway suite. declare(strict_types=1); Mago/Rector clean. Do NOT run composer test:e2e*.
- Out of scope: RemoteShell/StartsRemoteShellProcesses/RunsInternalCommands method signatures; the RemoteHostExecutor/RemoteOrbitGatewayExecutor start() implementations; the apps/docs Librarian TransitionalSshConsumerFinder (unless it references RemoteExecutor.php which is already in its EXCLUDED_PATHS).

## Proof

- Verification:
  - focused: passed - RemoteShell/executor/provisioning Pest green (worktree + operator VM)
  - broader: passed - full gateway Pest 6930 green; `composer quality-check` on clean commit `1fe426f20e29aff6937baae2ed4c28898a9e18e6` exit 0, dirty false, 45/45 subgates zero (`.orbit/quality-gates/quality-check-2026-08-18T030208Z-5a42c30f9ea7.json`)
  - runtime: passed - candidate=1fe426f20e29aff6937baae2ed4c28898a9e18e6; venue=retained-incus; environment=dev-fixture; expected=narrowed RemoteShell/StartsRemoteShellProcesses/RunsInternalCommands contracts green in retained operator VM with capable-executor start() retained; observed=300 passed 1379 assertions 0 failures; result=passed; command=ssh beast incus exec orbit-e2e-dev-52d37d-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/gateway && DB_DATABASE=/tmp/768fix-test.sqlite APP_ENV=testing HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan test tests/Unit/Services/RemoteShell tests/Feature/Services/SshRemoteShellTest.php tests/Feature/Services/Operations/ProvisioningAgentInstallerTest.php --compact'; evidence=`.orbit/evidence/solo-768-retained-incus-proof.md`
- Blast radius: complete - evidence=repository-wide rg sweep + full gateway Pest + quality-check; result=aggregate RemoteExecutor interface and startInternal fully removed, capable-executor start() coverage retained, no residual genuine references (`.orbit/evidence/solo-768-blast-radius-inventory.md`)
- Review: passed - independent Claude reviewer (2501); delta re-review of amended candidate PASS; human-judgment=not-required
- Reviewed feature tip: 1fe426f20e29aff6937baae2ed4c28898a9e18e6
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 1fe426f20e29aff6937baae2ed4c28898a9e18e6
- Accepted main tip: 0031dd041ac72d3d6c05732d5e0ab0cad3c8cfc9

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
