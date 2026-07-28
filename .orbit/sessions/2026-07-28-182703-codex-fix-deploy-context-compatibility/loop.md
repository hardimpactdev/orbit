# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `.orbit/loop.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-fix-deploy-context-compatibility`
- Branch: `codex/fix-deploy-context-compatibility`

## Goal

Deployment run context preserves the documented `app_*` variables and nested
`app` metadata while retaining the newer project aliases, so existing release
steps render safe absolute paths.

## Scope

- Owned: deploy run context and its focused gateway regression coverage.
- Constraints: preserve current project/instance aliases and all unrelated
  rename behavior; use the existing deployment renderer and test harness.
- Out of scope: changing stored Hauzer deployment steps or unrelated
  project/instance vocabulary.

## Proof

- Verification:
  - focused: passed - 23 deploy manager tests, 94 assertions; Mago scoped
    format and analysis passed
  - broader: passed - `composer quality-check` at merged candidate
    d3c13a15185c6e701836dcac787ec677d5e7c974; receipt
    `.orbit/quality-gates/quality-check-2026-07-28T162235Z-80519e44af33.json`
  - runtime: passed - source-mounted retained Incus run persisted both legacy
    and project aliases and rendered `{{ app_path }}` to the safe application
    path; `.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=repository-wide gateway, CLI, and deploy-documentation search; result=one run-context producer and no unresolved competing contract
- Review: passed - human-judgment=not-required
- Reviewed feature tip: d3c13a15185c6e701836dcac787ec677d5e7c974
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: d3c13a15185c6e701836dcac787ec677d5e7c974
- Accepted main tip: fcd592446ea9a24a176e083ed9d42e327bd3d8f4

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must record why it is
not required or include complete repository-wide evidence before acceptance;
`gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
