# Signal: Routine Review Scope Is Changed Files

Status: guarded
First seen: 2026-06-23
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: doctor-panel-human-rendering
Source commit: none
Signal type: review-comment
Guardrail target: HARNESS.md, .agents/review-personas/docs-librarian.md
Guardrail change: current review-scope slice
Related signals: harness-signals/2026-06-23-solo-role-matrix-needed.md
Superseded by: none
Tags: review, scope, documentation, librarian

## Signal

The user clarified that routine review should inspect changed files and cited
evidence, not constantly review the entire project. Project-wide patterns are
context for judging the diff, while full audits are separate tasks.

## Prior Occurrences

No prior durable signal record existed. This surfaced while designing additional
review personas and a docs/librarian agent path.

## Missing Guardrail

The first CLI reviewer persona scoped itself to the command under review, but
the root harness did not yet state the general review boundary for every
reviewer persona.

## Guardrail Change

`HARNESS.md` now includes a `Review Scope` section. The docs/librarian reviewer
also says routine review covers changed files, named authority docs, and cited
evidence, while broad drift scans belong to `auditing-docs-drift`.

## Verification

`rg -n "Review Scope|changed files|full-project docs audit|auditing-docs-drift" HARNESS.md .agents/review-personas/docs-librarian.md harness-signals/2026-06-23-review-scope-changed-files.md`
shows the review boundary is discoverable.

## Reappearance Check

If a routine reviewer expands into a broad project audit without the user asking
for one, mark this record `recurring` and add the changed-files rule to the
specific reviewer persona that drifted.

## Curation Notes

Keep as the general review-scope guardrail.
