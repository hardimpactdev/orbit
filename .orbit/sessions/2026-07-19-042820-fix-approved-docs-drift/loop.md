# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/4/scratchpad/docs-audit-final--316`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-fix-approved-docs-drift`
- Branch: `codex/fix-approved-docs-drift`

## Goal

Align Orbit's intent ledger, product documentation, executable coverage, and
implementation with all 18 approved docs-drift findings, including the revised
rule that workspaces are strictly app-dev-only, and land the proven result on
main.

## Scope

- Owned: `PRODUCT_DECISIONS.md`, affected `apps/docs/content/**` authority and
  command contracts, generated documentation artifacts, and the focused
  CLI/gateway/core/SDK tests or implementation required to match those
  contracts.
- Constraints: apply the approved synthesis from scratchpad 316; keep
  workspaces app-dev-only; preserve the primary checkout; keep docs, tests, and
  code aligned; never run `composer test:e2e*`; reject unrelated cleanup.
- Out of scope: new drift categories outside the approved findings, deployment,
  release work, and unrelated runtime or UI changes.

## Proof

- Verification:
  - focused: passed - A5 activation closure 130 tests / 438 assertions; all
    approved drift-focused suites passed
  - broader: passed - exact `composer quality-check` at
    `.orbit/quality-gates/quality-check-2026-07-19T021046Z-35452bdedfeb.json`;
    exact `composer docs-lint` at
    `.orbit/quality-gates/docs-lint-2026-07-19T021107Z-5d4ddb0f2cb3.json`;
    secret scan passed
  - runtime: passed - topology=dev-f8b9e7;
    kind=operator_gateway_app-dev_app-prod; roles=operator,gateway,dev,prod;
    Solo process=1089; evidence=`.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=main..HEAD diff review; git diff --check; focused gateway Pest 57 tests/220 assertions; exact quality-check and docs-lint artifacts exit 0; `.orbit/evidence/runtime-proof.txt`; result=A1-A16/C1-C2 and all app-prod activation paths pass
- Review: passed - independent general review; human-judgment=not-required
- Reviewed feature tip: 5fefd30b7449b2d2c7bc8e451fca3e0df7d2eab5
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 5fefd30b7449b2d2c7bc8e451fca3e0df7d2eab5
- Accepted main tip: 4e5aa01f37987b27c7c37495b4f0ee624833ecae

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be either a
reasoned `not-required` result or a complete evidence/result record before
acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
