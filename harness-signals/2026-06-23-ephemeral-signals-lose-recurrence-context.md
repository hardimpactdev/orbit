# Signal: Ephemeral Signals Lose Recurrence Context

Status: guarded
First seen: 2026-06-23
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: codex/root-harness-anchor-review-ui
Source commit: 38ff38aa
Signal type: review-comment
Guardrail target: harness-signals/, LOOP.md.example, HARNESS_SIGNALS.md, .agents/skills/implementing-features/SKILL.md
Guardrail change: current harness signal ledger slice
Related signals: harness-signals/2026-06-23-guardrail-target-terminology.md, harness-signals/2026-06-23-loop-not-wired-to-implementation-skill.md
Superseded by: none
Tags: signal-ledger, compound-engineering, recurrence

## Signal

The user pointed out that signals should not all be ephemeral. Across ten
worktrees, recurring signals are useful evidence: they show whether a guardrail
worked, whether the same issue is reappearing, and whether the guardrail needs
to be tightened.

## Prior Occurrences

This extends the two earlier harness-slice signals about terminology and
workflow wiring. Those signals were already resolved, but they had no durable
place to show recurrence history.

## Missing Guardrail

The loop could distill a signal into a guardrail target, but it did not preserve
curated signal records for search, cross-reference, recurrence checks, or
retirement.

## Guardrail Change

`harness-signals/` now stores curated signal records with status, recurrence,
guardrail-target, verification, and reappearance guidance. `LOOP.md.example`,
`HARNESS_SIGNALS.md`, `HARNESS.md`, and `implementing-features` point agents to
search and update the ledger before treating a signal as new.

## Verification

Searchable records now exist under `harness-signals/`, and the root harness
workflow points to the ledger. `composer docs-lint` should remain the document
quality gate for this slice.

## Reappearance Check

If agents keep reporting signals only in final prose without adding or updating
records, make signal-ledger checks part of a reviewer persona or static docs
check.

## Curation Notes

Keep as the ledger bootstrap signal. If a later curation workflow supersedes
this file, mark it retired and point `Superseded by` at the newer curation
record or workflow doc.
