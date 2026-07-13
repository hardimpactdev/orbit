# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/4/scratchpad/docs-audit-final--306`
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-agent-transport-drift-followup
- Branch: codex/agent-transport-drift-followup

## Goal

Remove the confirmed post-migration transport drift so normal runtime commands
use Agent-push contracts, provisioning/bootstrap remains the sole SSH lane, and
failed `node:manage` Agent convergence retains the operator's managed intent for
later doctor repair.

## Scope

- Owned: confirmed A2, A3, B1, B2, B3, B4, B6, C2 residuals plus stale active
  skill/profile selector examples and generated/linter artifacts caused by those
  fixes.
- Constraints: tests first for behavior changes; preserve provisioning/bootstrap
  SSH; no E2E; preserve unrelated dirty files on `main`.
- Out of scope: provisioning transport redesign, broad `RemoteShell*` internal
  DTO renames, and unrelated command or documentation cleanup.

## Proof

- Verification:
  - focused: passed - 153 tests / 908 assertions across node management, fleet
    update targeting/execution, tool/deploy Agent failures, and the retained
    process Docker runtime manager; the review correction suite passed 18 tests /
    96 assertions; 20 docs/inventory tests / 10,344 assertions passed with
    schema-v2 inventory freshness and docs lint green
  - broader: passed - exact-commit `composer quality-check` passed all 9 units; profile
    `.orbit/quality-gates/profiles/2026-07-13T07-23-22Z-9b4310df2269`;
    `composer docs-lint` and `composer quality-gate:final-check` passed for
    committed candidate `9b4310df22693b5d9b9535de766d68e6ca87339d`
  - runtime: passed - retained Incus `dev-37718d`; kind=`operator_gateway_agent`; provider=`incus`; host=`beast`; roles=`operator,gateway,agent`; Solo terminal=`solo://proj/4/process/1070`; runtime=`/home/orbit/orbit-run`; launcher=`/home/orbit/orbit-run/bin/orbit`; exact candidate source SHA-256 matched locally; `orbit node:manage --user=orbit --json` returned `node.agent_unreachable` without SSH fallback and the gateway retained `user=orbit`, `platform=ubuntu_26-04`, `managed=true`; no `test:e2e` command was run
- Review: passed - human-judgment=not-required - independent general reviewer; no findings
- Reviewed feature tip: 9b4310df22693b5d9b9535de766d68e6ca87339d
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 9b4310df22693b5d9b9535de766d68e6ca87339d
- Accepted main tip: 9c631156b11ff122d387bf4c103f5ead5f6cad78

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`.
