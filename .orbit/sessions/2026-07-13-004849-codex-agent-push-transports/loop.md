# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-agent-push-transports
- Branch: codex/agent-push-transports

## Goal

Convert every exact-marked non-provisioning SSH consumer to Agent push or gateway-local execution so the generated inventory reports 0 transitional SSH consumers while retaining provisioning/bootstrap SSH.

## Scope

- Owned: `apps/cli/`, `apps/gateway/`, `packages/core/`, affected command authority under `apps/docs/content/`, `PRODUCT_DECISIONS.md`, and generated SSH inventory/docs checks.
- Constraints: docs, tests, and implementation stay aligned; remove public transitional transport selectors; preserve provisioning/bootstrap SSH consumers; use typed allowlisted Agent `binary + argv` envelopes for node-local work.
- Out of scope: provisioning/bootstrap SSH, operator-owned break-glass SSH outside Orbit commands, E2E execution, and unrelated transport or command cleanup.

## Proof

- Verification:
  - focused: passed - SSH inventory 12 provisioning, 0 transitional, 0 unmarked; bootstrap/eligibility/controller/selector/role-assignment tests passed (96 tests, 401 assertions); gateway 4679 tests, CLI 2239 tests, SDK 132 tests, docs 141 tests, TypeScript typecheck, generated docs contracts, and Mago format checks passed
  - broader: passed - `composer quality-check` at `d1f3994ae085dc6a46fbd5315a6179acb78857fa`; receipt `.orbit/quality-gates/quality-check-2026-07-12T224419Z-7ed43bbd38a9.json`; all subgates passed
  - runtime: passed - retained Incus `dev-4bbe7e` (`operator_gateway_agent`) on `beast`; exact-tip provisioning began with no installed Agent binary, config, unit, process, or listener, then `ProvisioningAgentInstaller` returned exit 0 with checksum confirmation and `agent-ready`; systemd active and binary SHA-256 `ad497e0e9b214aa5790fd6808e9a00f793a8b51e660fb4174cdcd5534c8559d9`; operator ran `orbit doctor --node=agent-1 --family=node --key=node.transport_unreachable --json` from `/home/orbit/orbit-run` through `/home/orbit/orbit-run/apps/cli/orbit`; healthy with 0 issues; activity 95 recorded `internal:executor:verify` succeeded on `agent-1` in 787 ms; evidence `.orbit/evidence/agent-push-transport.md`
- Review: passed - independent exact-tip reviewer found no actionable findings after provisioning bootstrap correction; human-judgment=not-required
- Reviewed feature tip: d1f3994ae085dc6a46fbd5315a6179acb78857fa
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: d1f3994ae085dc6a46fbd5315a6179acb78857fa
- Accepted main tip: 442fa202216262fae87f34063b202763fd11bbc2

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`.
