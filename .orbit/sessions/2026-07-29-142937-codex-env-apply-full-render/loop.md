# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Codex task 019fa8f4-96e7-70d3-b9a0-e52f607f84df
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-env-apply-full-render
- Branch: codex/env-apply-full-render

## Goal

`instance:env set --apply` writes the complete effective instance environment
from authoritative gateway state, so consecutive applies cannot truncate a
remote `.env` to the latest mutation.

## Scope

- Owned: instance env docs, gateway renderer/controller/applier, focused Pest coverage.
- Constraints: preserve secrets in the internal apply map while redacting them
  from API render output; no production process mutation before the full env is
  restored; no GitHub release.
- Out of scope: Hauzer code, workspace env behavior, unrelated Beast Agent
  artifact-confirmation race.

## Proof

- Verification:
  - focused: passed - gateway Pest 8 tests / 86 assertions; scoped Mago
    analyze and lint clean.
  - broader: passed - `composer quality-check`;
    `.orbit/quality-gates/quality-check-2026-07-29T121512Z-d66b38924d5a.json`
  - runtime: passed - two consecutive full applies on retained Incus topology
    `dev-f33849` retained the first mutation, the second mutation, and the
    fixture environment; exact venue, command, ownership, count, and key-name
    hash are retained at `.orbit/evidence/runtime-proof.txt`.
- Blast radius: complete - evidence=repository-wide `composer quality-check` plus retained production-style consecutive-apply proof; result=all automated gates passed and the exact remote `.env` retained complete state.
- Review: passed - human-judgment=not-required; independent general review
  found no actionable findings and classified the blast radius complete.
- Reviewed feature tip: 297c8785d73a4bdf32240402781ddc1dee277218
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 297c8785d73a4bdf32240402781ddc1dee277218
- Accepted main tip: 00589f2cde140a9edf891e6ac8b3d5c74a042dce

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
