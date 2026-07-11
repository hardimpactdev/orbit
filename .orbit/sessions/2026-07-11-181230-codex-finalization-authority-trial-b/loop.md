# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo plan 294 / master 295 - non-observable Trial B
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-finalization-authority-trial-b
- Branch: codex/finalization-authority-trial-b

## Goal

Make finalization documentation truthfully distinguish validation from mutation,
and make the CLI stdin sentinel stable under fresh-worktree suite contention.

## Scope

- Owned: `HARNESS.md`, `.agents/skills/implementing-features/SKILL.md`,
  `apps/gateway/tests/Feature/E2ESupport/FeatureFinalizationGateTest.php`, and
  `apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php`.
- Constraints: non-observable docs/tooling trial; test-first contract correction;
  no E2E command; automated acceptance; preserve unrelated files.
- Out of scope: product behavior, finalization-gate behavior, acceptance routing,
  or a general timeout policy.

## Proof

- Verification:
  - focused: passed - two canonical fresh-worktree preparations timed out only
    the 2-second stdin sentinel; five focused repetitions in each worktree and
    both full-suite retries passed; the 10-second sentinel passed; the
    finalization suite passed 121 tests with 298 assertions; the focused stdin
    sentinel passed with 6 assertions; PHP syntax and `git diff --check` passed
  - broader: passed - `composer docs-lint` completed with zero errors; exact
    clean merge tip `ee864f85cc4ec35b870656ec57d4298e601a7bab` passed all 43
    aggregate subgates; artifact
    `.orbit/quality-gates/quality-check-2026-07-11T160602Z-38f9c12231fc.json`
  - runtime: not applicable
- Review: passed - exact-tip spec and fresh general reviews found no findings; human-judgment=not-required because this docs/test alignment is fully machine-decidable
- Reviewed feature tip: ee864f85cc4ec35b870656ec57d4298e601a7bab
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: ee864f85cc4ec35b870656ec57d4298e601a7bab
- Accepted main tip: dcdf77c2a701468873adce5adab459a2fa293c75

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
