# Signal: Manual Loop Was Not Wired Into Feature Execution

Status: guarded
First seen: 2026-06-23
Last seen: 2026-06-23
Source worktree: codex/root-harness-anchor-review-ui
Source commit: b269f590
Signal type: review-comment
Guardrail target: .agents/skills/implementing-features/SKILL.md
Guardrail change: 38ff38aa
Related signals: none
Tags: implementing-features, workflow, loop-engineering

## Signal

The root loop docs were useful, but a new feature implementation could still
skip them because `.agents/skills/implementing-features/SKILL.md` did not yet
require reading or reporting harness signals.

## Prior Occurrences

No prior durable signal record existed. The issue surfaced while walking
through how a future feature implementation would use the new harness files.

## Missing Guardrail

The durable docs existed, but the main execution workflow did not make them
part of the agent path.

## Guardrail Change

The implementation skill now makes agents read `HARNESS.md`, `LOOP.md`, and
`HARNESS_SIGNALS.md`; triage durable signals during the slice; and include a
`Harness signals` block in the implementation report.

## Verification

`rg -n "HARNESS.md|LOOP.md|HARNESS_SIGNALS.md|Harness signals|guardrail target|durable harness signal|feedback loop" .agents/skills/implementing-features/SKILL.md`
shows the workflow hooks, and `composer docs-lint` exited 0.

## Reappearance Check

If future implementation reports omit durable signal triage, tighten the report
template or add a review-persona check before merge.
