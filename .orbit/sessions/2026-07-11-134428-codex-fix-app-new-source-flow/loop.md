# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: User-reported `orbit app:new` prompt-contract bug in the current Codex task
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-app-new-source-flow
- Branch: codex/fix-app-new-source-flow

## Goal

`orbit app:new` resolves node, slug, and an explicit new-from-template or clone
source plan before any side effect, then creates and installs that source on the
selected node with docs, tests, and retained-node behavior aligned.

## Scope

- Owned: `PRODUCT_DECISIONS.md`; the `app:new` product/technical docs; CLI
  `app:new` and internal source creation; gateway app-store/source dispatch;
  focused CLI and gateway Pest coverage.
- Constraints: preserve `--repo` clone compatibility; add deterministic
  non-interactive template inputs; create template-derived GitHub repositories
  as private by default using only target-node GitHub authentication; resolve
  and validate all source inputs before the gateway request; never run
  `composer test:e2e*`; preserve unrelated main-checkout state.
- Out of scope: cleaning the failed `test` app/path on NMBP; broad app runtime
  redesign; generic source-creation error rendering changes unrelated to this
  contract.

## Proof

- Verification:
  - focused: passed - CLI 123 tests / 544 assertions; gateway 52 tests /
    402 assertions; core 127 tests / 534 assertions; SDK 2 tests / 4
    assertions; full docs lint passed with no errors; scoped Mago lint and
    analysis, secret scan, and `git diff --check` passed.
  - broader: passed - `composer quality-check` exited 0 for committed tip
    `ee65fc0a9a685e0f7d3026e94fe2482d09dbbcf9`; artifact
    `.orbit/quality-gates/quality-check-2026-07-11T111115Z-bf51380ffde2.json`
    records `dirty=false` and all subgates at exit 0. The evidence-only
    `composer quality-gate:final-check` exited 0; triage classified its 47s
    overall / 30.4s CLI Pest / 45.8s gateway Pest comparisons against the
    2026-06-26 local baseline as warning-only performance evidence, with no
    feature rerun or baseline refresh warranted. Its standalone docs-lint
    artifact warning is superseded by the exact-commit quality artifact's
    passing `docs_lint` subgate.
  - runtime: passed - retained Incus topology `dev-899ef9`
    (`operator_gateway_app-dev`), roles operator/gateway/app-dev/database, Solo
    PTY process `1045`. Zero-argument interactive template and clone branches
    both completed source creation, registration, and runtime application;
    target origins and fixed private-template `gh` argv/environment matched the
    contract; malformed credential input was rejected and registered no app.
    Evidence: `.orbit/evidence/app-new-retained-proof.md`. Topology released (3
    instances reaped); no `composer test:e2e*` command ran.
- Review: passed - fresh general reviewer found no actionable P0-P2 findings - human-judgment=not-required
- Reviewed feature tip: ee65fc0a9a685e0f7d3026e94fe2482d09dbbcf9
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: ee65fc0a9a685e0f7d3026e94fe2482d09dbbcf9
- Accepted main tip: aa51ba7f79cefdbe25cd8216e64126e6e1b02bb6

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
