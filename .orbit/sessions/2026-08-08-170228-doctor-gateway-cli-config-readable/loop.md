# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: /Users/nckrtl/orbit/.worktrees/doctor-gateway-cli-config-readable
- Branch: doctor-gateway-cli-config-readable

## Goal

Node Doctor reports a discriminating, restorable finding when the gateway host Orbit runtime user cannot read the CLI config the force_remote_host lane depends on, so the gateway can no longer be reported healthy while that lane is unusable.

## Scope

- Owned: `apps/gateway/app/Services/Nodes/GatewayCliConfigAccessProbe.php`, `apps/gateway/app/Services/Nodes/PosixReadAccess.php`, `apps/gateway/app/Services/Nodes/NodesProbe.php`, `apps/gateway/app/Services/Doctor/IssueCatalog/NodeDoctorIssueDefinitions.php`, `apps/gateway/tests/Unit/Services/Nodes/`, `apps/docs/content/domains/1_node/node-doctor.md`
- Constraints: The check must not dispatch through force_remote_host, or it cannot distinguish an unreadable config from an unrelated transport failure. Read the host view under `ORBIT_HOST_PATH_PREFIX` only. Gateway node only; no host prefix means out of scope. Restore reuses the landed ownership repairer and preserves owner-only modes. No live remediation.
- Out of scope: todos 200, 201, 204; `DeployController` request activity logging; PHP snapshot/runtime inheritance, `php:list`, workspace/default behavior and task #16 (owned by process 1488).

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact` 5888 passed 4 skipped at the merged tip; new probe file red first with `Class "App\Services\Nodes\GatewayCliConfigAccessProbe" not found`; two mutation checks - forcing the access predicate to always permit kills 6 tests, and reverting the restore target to the host view fails the new restore-target test
  - broader: passed - `composer quality-check` exit_code 0 at candidate HEAD with a clean worktree, artifact `.orbit/quality-gates/quality-check-2026-08-08T145228Z-723a04ace10e.json`
  - runtime: passed - candidate=2350b6d7c4e06853606aa23a10680d92955583af; venue=retained-incus; environment=dev-fixture; command=php artisan tinker running NodesProbe diff and reconcile on fixture gateway orbit-e2e-dev-c56c84-gateway; expected=node Doctor reports a genuine restorable finding naming the unreadable path when the gateway host runtime user loses access to its own CLI config and restore repairs it through the writable config root; observed=findings went 0 then 1 then 0 across healthy, broken and restored states with disposition genuine_drift and owner-only modes 700 and 600 unchanged, and against a read-only host view matching the deployed Swarm mount posture the pre-fix chown target raises EROFS while the shipped target succeeds; result=passed; evidence=`.orbit/evidence/doctor-gateway-cli-config-retained-incus.md`
- Blast radius: complete - evidence=bounded repository-wide inventory of node issue-code registration surfaces (sibling `node.gateway_ca_mismatch`) plus a `final`/arch-test sweep across `apps/` and `packages/` and `apps/gateway/mago.toml`; result=node issue codes live only in the gateway issue catalog and node-doctor.md, both covered here alongside NodesProbe diff/reconcile/restoreSupport, and dropping `final` has no arch test, no lint rule, one subclass repo-wide, and non-final precedent at GatewaySwarmInstaller, so no affected surface is unresolved
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 2350b6d7c4e06853606aa23a10680d92955583af
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 2350b6d7c4e06853606aa23a10680d92955583af
- Accepted main tip: 4324103ae6ab2f0a8d01d66d8cab327386e90c0e

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
