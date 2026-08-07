# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none (compact local loop)
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-nmbp-instance-defects
- Branch: codex/nmbp-instance-defects

## Goal

The five 0.1.190 NMBP defects are fixed with regression coverage: (1) instance:register refuses ambiguous multi-instance apps instead of silently moving one, (2) php:use --instance still writes the documented app-owned shared PHP policy but names every sibling instance the change fans out to instead of mutating them silently, (3) node:permissions --remove accepts stale/unknown permission names and doctor marks that drift restorable, (4) process:start decides launchd outcomes by polling across the throttle window and resets a stuck unit's spawn state via bootout, (5) managed-service catalog supports postgres 17 and a published-port override for valkey/single-port services.

## Scope

- Owned: apps/cli, apps/gateway, packages/core, packages/sdk, apps/docs/content (command docs for changed signatures), PRODUCT_DECISIONS.md (ledger entries for contract changes)
- Constraints: no unrelated behavior changes; existing instances/apps must keep working; removal paths must never validate against the live catalog; "moved" requires explicit intent; scope reported in JSON output must equal scope written
- Out of scope: E2E lanes (human-only), live-node verification beyond what the user later runs, hibernation redesign beyond fail-loud/retry behavior named in the report

## Proof

- Verification:
  - focused: passed - per-defect failing-first Pest regressions across apps/gateway (AppRegisterController 17, UsePhpRuntime 7, NodePermissionsController 18, DoctorRestoreSupport/NodesProbe 105, RuntimeColdActivation surface 53, ProcessServiceCatalog 36, ToolInstallController 37) and apps/cli (full suite 2533 passed; launchd internal command 16)
  - broader: passed - artifact `.orbit/quality-gates/quality-check-2026-08-07T122416Z-5a91daec32cf.json` on exact commit b2279fcab99aba3a040822e9aaa33935d51775ef, exit_code 0 with every subgate 0, rerun after merging advanced main (Laravel Zero 13 and Pest 5 for apps/cli) with apps/cli dependencies reinstalled against the new lock and the full CLI suite green on the new stack. An earlier packet claim that gateway_mago_analyze failed identically on clean main was wrong and is retracted: it came from running unscoped `mago analyze` instead of the gate's `mago analyze app config database`. The eight analyzer errors were introduced by this branch in app/ and are fixed (null-safe property reads in AppRegisterController and AppRegistrar, query-builder typing in NodesProbe reconcileAccessPermissions, stored-config narrowing in ToolInstaller). One earlier run showed cli_pest red in InternalAppSourceCreateCommandTest, unrelated to this diff and reproduced green on the exact sharded lane and standalone; its cause is that test's afterEach glob-deleting every /tmp/orbit-app-source-bin-* directory, recorded as a follow-up
  - runtime: passed - candidate=b2279fcab99aba3a040822e9aaa33935d51775ef; venue=browser; environment=dev-fixture; target=development-runtime wake error page for an app-instance scope; expected=a terminally unsuccessful activation run renders its recorded error reason as legible centered prose above the explicit retry link; observed=503 whose activation-state header carries the terminal error value, no Retry-After, no script-src in CSP, reason "Orbit gateway bootstrap image is not configured and the running gateway service image could not be resolved." and retry link to /?orbit-wake-retry=1 rendered on one centered axis in Chrome at 1436x840, with the reason wrap defect this run exposed corrected and re-rendered, and with the renders captured at aba5d58b0 before the main merge and rebound to this candidate because the delta leaves the wake-page chain byte-identical per the manifest; result=passed; evidence=`.orbit/evidence/nmbp-instance-defects-wake-page/PROOF-MANIFEST.md`
- Blast radius: complete - evidence=repository-wide sweep for scope-misreport, sibling-mutation, and hard-default-reset shapes across apps/cli command signatures and apps/gateway write paths, plus reviewer-run verification of the gate command in bin/quality-check.sh against apps/gateway/mago.toml source paths and a trace of the analyzer delta against the wake-page render chain; result=two additional same-shape defects found and fixed on this branch (instance:register shared-policy fan-out unreported; tool:install resetting stored expected_version/install_users), five surfaces recorded as follow-ups (instance:root enactment fan-out reporting, firewall converge overwriting reason/owner/protected, node:grant already-granted action label, tool:install --status contract drift, InternalAppSourceCreateCommandTest fake-bin glob isolation), remainder checked clean
- Review: passed - human-judgment=required; independent general reviewer VERDICT=PASS, BLAST_RADIUS=complete on exact candidate b2279fcab99aba3a040822e9aaa33935d51775ef; reviewer proved the merge content-neutral by SHA-comparing the pre- and post-merge candidate patches, and identified that main advanced symfony/process 7.4.13 to 8.1.0, a major bump of the component LocalLaunchdServiceAction drives for every launchctl call, covered here because the launchd tests exercise the real component against a fake launchctl (success, non-zero exits 37 and 5, stderr capture, exit-code propagation, repeated construction in the readiness poll)
- Reviewed feature tip: b2279fcab99aba3a040822e9aaa33935d51775ef
- Acceptance venue: browser
- Acceptance: accepted - user @ solo://projects/orbit/processes/1488#session-bfe7499f07396ea7
- Accepted feature tip: b2279fcab99aba3a040822e9aaa33935d51775ef
- Accepted main tip: 7d9bf4b48248c6af58b75a4d488778a279ff066c

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
