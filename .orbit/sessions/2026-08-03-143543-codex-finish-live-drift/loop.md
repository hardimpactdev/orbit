# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-finish-live-drift`
- Branch: `codex-finish-live-drift`

## Goal

Ship the approved drift-resolution contract improvements as one clean candidate:
exact `project.instance` name precedence over aliases, complete Doctor role
reporting (`roles` additive field), derived non-secret autonomous-agent tool
endpoint metadata, tool-family Doctor HTTPS URL health for installed agent tools,
one shared gateway-to-CLI tool run-script action contract in packages/core,
retained `operator_gateway_agent` readiness that prepares the managed agent
runtime user and durable source CLI/home access (including agent config
directory + file ACLs across owner-only atomic saves), and aligned product docs.

## Scope

- Owned:
  - `AppSelectorResolver` / `WorkspacePlacement` instance selector precedence
  - Doctor report scope/fleet node `roles` + human panel visibility
  - `ToolPayloadMapper` derived agent tool endpoints (no redundant config copies)
  - ToolsProbe agent consumer URL health check (tool family; proxy owns routes)
  - packages/core shared tool run-script actions + CLI/gateway consumers + inventory test
  - apps/e2e retained topology agent runtime readiness (in-memory coverage only)
  - `OrbitConfigStore` durable agent directory (`u:agent:--x`) + file (`u:agent:r--`) ACLs on save
  - apps/docs/content + PRODUCT_DECISIONS + focused Pest
- Constraints:
  - exact checkout only; preserve unrelated work; never touch main
  - no `composer test:e2e*`, no live fleet mutation, no merge/release
  - TDD: literal red focused tests before implementation
  - do not broadly allow 0640 or weaken owner-only checks
  - preserve scope.role=fleet; additive roles only
- Out of scope:
  - LAND/merge, archive, release, deploy (stop before merge after acceptance)

## Proof

- Verification:
  - focused: passed - directory `u:agent:--x` after chmod 0700 + file `u:agent:r--` after rename; fail-closed directory/file setfacl; non-agent hosts skip; assessor/file validation coverage
  - broader: passed - `composer quality-check` on clean HEAD `064642c8888915a3d167c776cb54d624fe240d24` dirty=false; artifact `.orbit/quality-gates/quality-check-2026-08-03T122829Z-0727e896cbc9.json`
  - runtime: passed - non-vacuous retained re-proof on `dev-9da8c1` kind `operator_gateway_agent` host `beast` instance `orbit-e2e-dev-9da8c1-agent` synced to HEAD `064642c8888915a3d167c776cb54d624fe240d24`. Evidence: `.orbit/evidence/2026-08-03-agent-config-acl-durability-dev-9da8c1.md`
    - Baseline after one-time ACL restore: agent `test -e config` exit 0; `head -c1` → `{`; mtime `12:14:10`
    - Atomic rewrite: `gateway:use default --json` as orbit with real `ORBIT_CONFIG_PATH` from `/home/orbit/orbit-run` → success, config-write-exit=0; mtime `12:14:10` → `12:29:35`
    - Post-rewrite directory ACL exact: `user::rwx` / `user:agent:--x` / `group::---` / `mask::--x` / `other::---`
    - Post-rewrite file ACL exact: `user::rw-` / `user:agent:r--` / `group::---` / `mask::r--` / `other::---`
    - As agent: `test -e config` exit 0; `head -c1` → `{`; `/home/agent/.local/bin/orbit gateway:list --json` → `active_gateway=default` + real gateway row; nonvacuous-proof-exit=0
    - Independent final reviewer re-ran retained `dev-9da8c1` probes: directory/file ACLs survived atomic rewrite; agent test/read real config; `gateway:list` returned real active gateway
- Blast radius: complete - evidence=independent final review inventory of ACL/config-store/agent-readiness surfaces plus retained topology re-probe on operator_gateway_agent; result=no remaining actionable finding on exact tip
- Review: passed - human-judgment=not-required - VERDICT=PASS BLAST_RADIUS=complete no actionable finding remains
- Reviewed feature tip: 064642c8888915a3d167c776cb54d624fe240d24
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 064642c8888915a3d167c776cb54d624fe240d24
- Accepted main tip: 4ac0b86351a01250381e4b3fd42295b736a14c68

## Status

- State: accepted
- Blocker: none
- Feature tip: 064642c8888915a3d167c776cb54d624fe240d24

## Feedback

- Events: `.orbit/feedback.jsonl`
