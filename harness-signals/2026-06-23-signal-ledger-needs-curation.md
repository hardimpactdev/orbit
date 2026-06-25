# Signal: Signal Ledger Needs Curation

Status: guarded
First seen: 2026-06-23
Last seen: 2026-06-23
Last reviewed: 2026-06-25
Source worktree: codex/root-harness-anchor-review-ui
Source commit: e0e96b50
Signal type: review-comment
Guardrail target: harness-signals/README.md, harness-signals/_template.md, LOOP.md, .agents/skills/implementing-features/SKILL.md
Guardrail change: current signal curation slice
Related signals: harness-signals/2026-06-23-ephemeral-signals-lose-recurrence-context.md
Superseded by: none
Tags: signal-ledger, curation, compound-engineering

## Signal

The user pointed out that durable signals can grow without bound. If old or
overlapping signals stay in the ledger, they make feature development harder by
adding noise and stale guidance.

## Prior Occurrences

This follows the earlier signal that ephemeral signals lose recurrence context.
The ledger solved persistence, but not long-term curation.

## Missing Guardrail

The ledger had statuses and a template, but no process to update, consolidate,
retire, or delete records when they drift or stop helping future work.

## Guardrail Change

`harness-signals/README.md` now defines curation triggers and outcomes:
Keep, Update, Consolidate, Mark recurring, Mark stale, Retire, and Delete.
The template now includes review and supersession fields. `LOOP.md` and
`implementing-features` point agents to curate records when searches return
stale, noisy, or recurring signals.

## Verification

The curation process is documented in the ledger README and discoverable from
the implementation workflow. `composer docs-lint` is the document quality gate
for this slice.

## Reappearance Check

If the ledger becomes noisy again, run a focused curation pass over the matching
records. If that keeps happening, add a review-persona check or lightweight
script that flags stale, redundant, or long-unreviewed records.

## Curation Notes

Keep while the manual curation process is being tried. Retire only after the
process has been folded into a stronger reviewer or automation slice.

The 2026-06-25 uniqueness pass reviewed all 26 signal records. No fully
redundant records were found. Related records were kept only when their
reappearance action, guardrail target, or triage question differed; the pass
normalized sparse records and added an explicit uniqueness rule to the ledger
README and template.
