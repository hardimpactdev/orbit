# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: /Users/nckrtl/orbit/.worktrees/gateway-config-root-host-ownership
- Branch: gateway-config-root-host-ownership

## Goal

Gateway install/update convergence keeps the bind-mounted config root owned by the canonical host Orbit uid/gid whenever it hardens those owner-only modes, so `0700` can never leave the host Orbit CLI unable to read its own config and disable the whole force_remote_host lane.

## Scope

- Owned: `apps/gateway/app/Services/Gateway/GatewayConfigRootOwnershipRepairer.php`, `apps/gateway/app/Services/Gateway/GatewaySwarmInstaller.php`, `apps/gateway/tests/Unit/Services/Gateway/`, `apps/gateway/tests/Feature/Services/Gateway/GatewaySwarmInstallerTest.php`, `apps/docs/content/tech-stack.md`
- Constraints: Preserve the documented `0700` directory and `0600` credential modes exactly. Resolve ownership only from the host home view under `ORBIT_HOST_PATH_PREFIX`, per `apps/docs/content/tech-stack.md`; never assign the image-internal account for a bind-mounted tree and never invent a uid policy. Fail closed when a prefix is present but the host home view cannot be resolved. No live remediation.
- Out of scope: Doctor coverage for host CLI config readability (todo 203), `invalid_token` diagnostics (todo 201), attached deploy-stream completion (todo 204), `DeployController` request activity logging, and the E2E `ssh_example` quoting defect (todo 200). Also excluded: `UpdateRunnerLauncher::launch()` and `RuntimeActivationRunnerLauncher` both call `File::ensureDirectoryExists($configRoot, 0o700)`, which is create-only and provably cannot re-harden an existing tree, so neither is the recurrence vector.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact` 5862 passed 4 skipped; reviewer-FIX deltas mutation-checked: removing the chown kills 3 tests, and reverting the repair to its former position fails the ordering guard; new unit file red first with `Class "App\Services\Gateway\GatewayConfigRootOwnershipRepairer" not found`
  - broader: passed - `composer quality-check` exit_code 0 at candidate HEAD with a clean worktree, artifact `.orbit/quality-gates/quality-check-2026-08-08T081030Z-8376dbc72947.json`
  - runtime: passed - candidate=647ba6cb560d02a1269d14683e197698836fb272; venue=retained-incus; environment=dev-fixture; command=php artisan tinker --execute bootstrapRuntimeConfig on fixture gateway orbit-e2e-dev-45ca61-gateway; expected=routine convergence restores the config root tree to the canonical host owner while preserving owner-only modes and the host Orbit CLI regains access to its own config; observed=with apps.php deleted beforehand so it must be created during the pass, ownership moved daemon:daemon to orbit:orbit and apps.php landed orbit:orbit 600, and node:list as the host orbit user returned success; result=passed; evidence=`.orbit/evidence/config-root-host-ownership-retained-incus.md`
- Blast radius: complete - evidence=bounded repository-wide ownership-boundary inventory (`chown` and `0o?700` across apps/gateway/app, apps/cli/app, packages, docker, bin); result=only three surfaces harden or own the gateway config root and all pair modes with an ownership pass (entrypoint.sh, GatewaySwarmInstaller, bin/install-orbit), while UpdateRunnerLauncher and RuntimeActivationRunnerLauncher are create-only and cannot re-harden, so no affected surface is unresolved
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 647ba6cb560d02a1269d14683e197698836fb272
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 647ba6cb560d02a1269d14683e197698836fb272
- Accepted main tip: 179ffa90c9ee445af910d806d5065808af125dad

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
