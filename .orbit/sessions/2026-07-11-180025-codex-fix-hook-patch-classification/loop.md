# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo plan 294 / master 295 - blocker correction before Trial B
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-loop-program-trial-b
- Branch: codex/fix-hook-patch-classification

## Goal

Prevent the Codex pre-tool hook from classifying inert apply-patch payload text
as an executing merge or cleanup mutation, and restore the approved automated
acceptance venue for repository tooling while preserving real runtime proof.

## Scope

- Owned: `bin/orbit-codex-pre-tool-use-hook`,
  `bin/orbit-codex-pre-tool-use-hook-test`, `bin/orbit-loop-contract.php`,
  `FeatureAcceptanceTest.php`, `McpConfigurationTest.php`, `HARNESS.md`, and
  the implementing-features skill.
- Constraints: test-first; patch-shaped text is inert but real shell merge and
  cleanup commands remain blocked; proof venues cannot substitute across
  different non-automated surfaces; no E2E command; preserve unrelated files.
- Out of scope: product behavior, CLI/server topology routing, venue-specific
  runtime implementation, or broad hook redesign.

## Proof

- Verification:
  - focused: passed - inert patch and repository-tooling venue regressions were
    reproduced red; hook tests passed; acceptance 29/29 with 110 assertions and
    architecture contract 24/24 passed; PHP syntax, formatting, and diff checks
    passed; malformed wrapped and duplicate-marker patch envelopes were also
    reproduced red and are now blocked
  - broader: passed - docs lint passed with zero errors; exact clean commit
    `479c6d4f240549caf57b39b759099952b71cb9e3` passed all 43 aggregate subgates;
    artifact
    `.orbit/quality-gates/quality-check-2026-07-11T155446Z-afc68f2f520e.json`
  - runtime: not applicable - repository tooling has no retained topology target
- Review: passed - independent exact-tip spec and general reviews found no findings; human-judgment=not-required because deterministic checks decide this harness-only behavior
- Reviewed feature tip: 479c6d4f240549caf57b39b759099952b71cb9e3
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 479c6d4f240549caf57b39b759099952b71cb9e3
- Accepted main tip: d59aedfdebb41f1b1c1875ac7950b4bb789bc831

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
