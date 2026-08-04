# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: /Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-04-opencode-polyscope-ade-removal-design.md
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade
- Branch: codex/remove-opencode-polyscope-ade

## Goal

Release A complete removal of OpenCode, PolyScope, and Agent IDE/ADE active product surface while preserving generic worktree workspaces and current Codex direct integration, with rollback-safe storage cleanup and removal-only legacy host teardown for Orbit-managed residue.

## Scope

- Owned: apps/cli, apps/gateway, apps/docs, apps/e2e (source only), packages/core, packages/sdk, packages/sdk-typescript, PRODUCT_DECISIONS.md, generators/baselines as needed
- Constraints: Release A only (no column drops); preserve Codex contract; historical migrations/decisions/archives stay; no composer test:e2e*; generators for generated artifacts; fail-closed Orbit-owned legacy teardown only
- Out of scope: Release B column drops; Codex rename; July docs-drift remediation; merge/accept/archive/cleanup; non-Orbit host installs

## Proof

- Verification:
  - focused: passed - removal, harness, catalog, process/doctor/permissions, CLI visibility/write, sdk/core
  - broader: passed - composer quality-check exit 0 on exact candidate HEAD; artifact `.orbit/quality-gates/quality-check-2026-08-04T120234Z-583348d01512.json`
  - runtime: passed - retained-incus topology id=dev-779aaa kind=operator_gateway_app-dev host=beast inspected role/node=dev/app-dev-1; commands `orbit tool:remove opencode-cli --node=app-dev-1 --force --json` and `orbit tool:remove polyscope-server --node=app-dev-1 --force --json`; both returned legacy_runtime_cleanup with process intent removal; post-absence host and gateway registry clean; non-owned sentinel survived; topology stopped via `composer e2e:incus -- --stop --id=dev-779aaa --json` with instances reaped and host resources gone; evidence `.orbit/evidence/ade-legacy-tool-remove-retained/PROOF-MANIFEST.md`, `.orbit/evidence/ade-legacy-tool-remove-retained/06-tool-remove-opencode-cli.json`, `.orbit/evidence/ade-legacy-tool-remove-retained/07-tool-remove-polyscope-server.json`, `.orbit/evidence/ade-legacy-tool-remove-retained/08-post-absence-host.txt`, `.orbit/evidence/ade-legacy-tool-remove-retained/09-post-remove-inventory.json`, `.orbit/evidence/ade-legacy-tool-remove-retained/10-post-absence-gateway-registry.json`, `.orbit/evidence/ade-legacy-tool-remove-retained/11-topology-stop.json`, `.orbit/evidence/ade-legacy-tool-remove-retained/12-topology-stop-host-verify.txt`
- Blast radius: complete - evidence=`repository-wide ADE/OpenCode/PolyScope inventory plus independent general reviewer on 03811c9180f8023616ab0013f779b7afa9223de0`; result=active product surface withdrawn; removal-only tool:remove residue paths retained; generic worktrees and Codex contract preserved; no actionable findings
- Review: passed - human-judgment=not-required; independent general reviewer VERDICT=PASS; BLAST_RADIUS=complete; no actionable findings on exact candidate 03811c9180f8023616ab0013f779b7afa9223de0
- Reviewed feature tip: 03811c9180f8023616ab0013f779b7afa9223de0
- Acceptance venue: browser
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 03811c9180f8023616ab0013f779b7afa9223de0
- Accepted main tip: 3cf92ed365a899042acb21b001563da22574dd98

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
