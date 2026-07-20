# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: current Codex task; explicit user process feedback
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-update-docs-audit-reporting
- Branch: codex/update-docs-audit-reporting

## Goal

Make every `auditing-docs-drift` user-facing report show the complete finding
list, with a plain-language explanation of the drift and the recommended fix
for every item, even when the user asks for a short summary.

## Scope

- Owned: `.agents/skills/auditing-docs-drift/SKILL.md` and the comparative
  fresh-agent verification needed to prove its output contract.
- Constraints: keep all findings; use simple writing; make `Drift` and `Fix`
  explicit per item; preserve evidence in the Solo scratchpads; do not weaken
  independent review or per-finding approval.
- Out of scope: changing the audit corpus, severity model, product docs,
  runtime code, or unrelated skills.

## Proof

- Verification:
  - focused: passed - the unchanged skill produced complete `Drift` plus `Fix`
    items in only 1 of 5 fresh-agent samples; the updated skill produced them
    in 5 of 5 matching samples and also passed separate completion-report and
    eight-finding mobile-summary scenarios; `git diff --check` passed
  - broader: passed - `composer quality-check` at exact candidate HEAD;
    artifact `.orbit/quality-gates/quality-check-2026-07-20T070500Z-fdde2e5e127d.json`
    records exit 0, a clean checkout, and every subgate passing; the supported
    CPU budget was reduced to 10 after higher-concurrency runs received
    aggregate-only SIGTERM exits while both affected Pest lanes passed directly
  - runtime: not applicable
- Blast radius: not-required - the change is isolated to one audit skill's
  reporting contract and does not change product authority, shared vocabulary,
  schemas, transport, or ownership boundaries
- Review: passed - human-judgment=not-required; independent general reviewer
  found no actionable findings
- Reviewed feature tip: be07535ca9e89dd2c3337fb3ad527a6fbd70350b
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: be07535ca9e89dd2c3337fb3ad527a6fbd70350b
- Accepted main tip: 1719bde6188541875d89b9f672393df45fc285a5

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
- Active: `feedback-20260720064143-da4e79b231f0`
- Promotion: `promotion-20260720064928-c8982ab7a89e` protects the complete
  plain-language `Drift` plus `Fix` output contract in the skill.

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must state either a
not-required reason or complete repository-wide evidence and its result before
acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact existing
inline-code file path; prose, directories, padded code spans, and partial paths
are not proof citations.
