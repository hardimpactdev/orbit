# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-doctor-drift-hardening`
- Branch: `codex/doctor-drift-hardening`

## Goal

Eliminate remaining false or inefficient live Doctor drift paths and the Hermes
empty-credential state through one coherent hardening feature: single
execution paths for DNS projection probing, runtime-unit naming, launchd
ownership, operation-token transport, home_perms restore, and Hermes
password/secret generation, plus one-step custom proxy lifecycle and compact
machine-mode Doctor progress transport.

## Scope

- Owned:
  - Node DNS projection probe scope (DNS consumer / VPN+gateway only)
  - Canonical bounded `ProcessRuntimeUnitName` shared by render/probe/restore
  - Launchd target ownership via placement/processNode (no Linux launchd paths)
  - `force_remote_host` only for gateway host-boundary targets
  - `node.security.home_perms` restore through authenticated node path
  - Hermes empty credential files treated as missing + non-empty process gate
  - One-step custom proxy add/remove lifecycle with safe partial failure states
  - Compact machine Doctor progress frames with full terminal reports
  - Product docs + PRODUCT_DECISIONS for home_perms restorable direction
  - Focused Pest coverage
- Constraints:
  - work only in this prepared worktree
  - no live topology mutation
  - no `composer test:e2e*`
  - no merge, push, deploy, release, or worktree cleanup
  - preserve unrelated work
  - do not weaken operation-token auth
- Out of scope:
  - LAND/merge
  - writing dead dnsmasq files onto non-DNS nodes
  - creating launchd plists on Linux

## Root causes (investigation)

1. **DNS**: `NodeDnsProjectionProbe` runs on every node probe while the fleet
   projection file is only consumed by the DNS runtime on the VPN/gateway host.
   Fan-out attributes the same shared artifact path to non-consumers.
2. **Runtime unit name**: `ProcessRuntimeUnitName` built unbounded names from
   the app, instance, scope, and process identifiers; launchd validity is max 64
   identity chars, so long workspace process names become
   `process.runtime_unit_unrenderable`.
3. **Launchd on Beast**: process selection uses placement, but launchd expected
   paths still used `Project::$node` in places; launchd on a Linux placement
   invents `/home/.../Library/LaunchAgents` instead of resolving a macOS
   execution owner or reporting platform mismatch.
4. **invalid_token**: `force_remote_host` is set whenever the gateway process is
   containerized, including AgentPush targets (ingress1). Token mint context is
   normalized for host-SSH (cwd/APP_KEY) that the Agent never sees →
   `invalid_token`. Gateway host path remains force_remote_host-only on gateway.
5. **home_perms**: probe reports weak `/home/{user}` mode; restore throws
   re-bake-only. `HomeDirectoryLockdownInstaller` already exists but is bake-time
   and hard-coded to `orbit`.
6. **Hermes**: `configureManagedDashboardScript` regenerates only when
   `! -f` secret files, preserving empty files; `relatedProcess` only checks
   `-f`, so empty password yields basic auth with length 0.

## Proof

- Verification:
  - focused: passed - ProcessRuntimeUnitName, HermesTool, SecurityInstallers, NodeSecurityPosture, ProcessesProbe, RemoteLocalExecutor, NodeDnsProjection, LaunchdPlist, ProcessOwnerContext, DoctorDnsProjectionRestore, custom proxy lifecycle failure states, compact Doctor terminal frames, and CLI output contracts
  - broader: passed - `ORBIT_QUALITY_CHECK_CPU_BUDGET=2 composer quality-check` exit 0 on exact accepted commit; artifact `.orbit/quality-gates/quality-check-2026-08-02T234127Z-dad34b0ce600.json`
  - runtime: passed - retained Incus topology `dev-2e5689` (`operator_gateway_agent`); source-mounted launcher/hash and fleet targeting in `.orbit/evidence/retained-dev-2e5689-launcher-and-nodes.txt`; compact progress plus full terminal report and DNS consumer-only attribution in `.orbit/evidence/retained-dev-2e5689-doctor-all.stream.jsonl`; Agent home restore changed `/home/orbit` from 0750 to 0700 in `.orbit/evidence/retained-dev-2e5689-home-perms-restore.stream.jsonl`; proxy add failure persisted recoverable intent in `.orbit/evidence/retained-dev-2e5689-proxy-add-gateway.txt`; proxy cleanup failure retained the registry row in `.orbit/evidence/retained-dev-2e5689-proxy-remove-gateway.txt`
- Blast radius: complete - evidence=repository-wide search of host execution, proxy status and warning vocabulary, Doctor stream transport, docs catalog, CLI, gateway, and manual E2E fixtures; result=all changed contracts aligned and independent re-review resolved all twelve findings
- Review: passed - independent general review and focused re-review approved - human-judgment=not-required
- Reviewed feature tip: 65bd9e7e7551aec0b8c83f7aac89caf3d644e9de
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 65bd9e7e7551aec0b8c83f7aac89caf3d644e9de
- Accepted main tip: d0d047a2bd6bb78c4a906b83acdbcead079e5e83

## Status

- State: accepted
- Blocker: none
- Feature tip: 65bd9e7e7551aec0b8c83f7aac89caf3d644e9de

## Feedback

- Events: `.orbit/feedback.jsonl`
