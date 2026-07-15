# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/features/2026-07-15-client-side-node-bootstrap.md`
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-client-side-node-bootstrap
- Branch: codex/client-side-node-bootstrap

## Goal

`orbit node:new` bootstraps a managed workload node through initiating-client
SSH, after which the gateway completes provisioning only through WireGuard and
Orbit Agent; the gateway never opens target SSH.

## Scope

- Owned: product decision and node-new docs; CLI node-new orchestration and
  client SSH runner; gateway bootstrap preparation/persistence/bundle/resume;
  focused CLI and gateway tests.
- Constraints: no gateway SSH or SSH key custody; no public/pre-WireGuard
  enrollment endpoint; secret bundle is not persisted in operation results;
  retries reuse compatible pending identity; no manual no-SSH fallback; no
  `composer test:e2e*`; preserve unrelated main-worktree changes.
- Out of scope: first-gateway bootstrap redesign; general operator SSH removal;
  unrelated fleet-update parallel test flake.

## Proof

- Verification:
  - focused: passed - overlapping completion regression 1 test/13 assertions;
    bootstrap controller and bundle 15 tests/134 assertions; Caddy CLI 14 tests/93 assertions; Agent tool-script CLI
    8 tests/21 assertions; security installers 6 tests/45 assertions; bake-app
    node 9 tests/64 assertions; bootstrap-resume CLI node suite 85 tests/324
    assertions; gateway node suite 40 tests/238 assertions; scoped Mago without
    errors, docs lint, formatting, and diff checks passed.
  - broader: passed - `ORBIT_QUALITY_CHECK_CPU_BUDGET=6 composer
    quality-check` at `a0b6854d50fecf9f6de79599afc6344dc5df2b35`; profile
    `.orbit/quality-gates/profiles/2026-07-15T17-44-26Z-a0b6854d50fe`.
    The bounded budget prevented gateway Pest from starving the CLI shards;
    all nine component areas passed. `composer docs-lint`,
    `bin/orbit-secret-scan`, and `composer quality-gate:final-check` passed at
    the same HEAD. Final-check reported timing warnings only.
  - runtime: passed - retained Incus topology `dev-449c69`
    (`operator_gateway`, host `beast`) proved initiating-client SSH bootstrap,
    pending retry identity reuse, WireGuard/Agent takeover, Docker/Caddy and
    security convergence, active completion, completed retry idempotency,
    WireGuard-only Agent and SSH listeners, public SSH closure, root key
    removal, absence of gateway SSH host-key custody, completed `node:new`
    recovery with public SSH already closed, unique WireGuard address index,
    no duplicate completion activity, and two concurrent completed retries at
    the exact candidate tip. Candidate
    `a0b6854d50fecf9f6de79599afc6344dc5df2b35`; evidence:
    `.orbit/evidence/client-side-node-bootstrap-retained-incus.md`.
- Review: passed - human-judgment=not-required - no findings; reviewer confirmed
  per-bootstrap serialization, durable transition-winner activity, failure
  non-regression, and meaningful overlapping-request coverage.
- Reviewed feature tip: a0b6854d50fecf9f6de79599afc6344dc5df2b35
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a0b6854d50fecf9f6de79599afc6344dc5df2b35
- Accepted main tip: 638b9920c75fb04a8ac1651c585ef1f80d0c0141

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
