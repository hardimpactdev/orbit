# Signal: Docs Librarian Persona Needed

Status: guarded
First seen: 2026-06-23
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: doctor-panel-human-rendering
Source commit: none
Signal type: review-comment
Guardrail target: .agents/review-personas/docs-librarian.md, .agents/skills/implementing-features/SKILL.md
Guardrail change: current docs/librarian slice
Related signals: harness-signals/2026-06-23-review-scope-changed-files.md
Superseded by: none
Tags: documentation, librarian, review-persona, claude

## Signal

Orbit feature work often starts with documentation alignment, and the user
already uses Claude for valuable docs-drift analysis. The harness needed a
focused docs/librarian reviewer and a documenter-worker path without turning
every docs review into a full audit.

## Prior Occurrences

Historic sessions repeatedly showed that docs, tests, and implementation must
stay aligned before a feature is accepted. The first CLI reviewer persona showed
the value of post-test review criteria, but documentation needed its own
reviewer because Orbit is contract-heavy.

One-time mini Codex-session backfill found 8 Orbit docs-audit or docs-drift
review sessions between 2026-05-21 and 2026-06-11, including repeated prompts
to review docs consistency audit findings from Solo scratchpads and a
Solo-managed `codex-drift-second-opinion` worker. That supports a dedicated
docs/librarian role rather than making every implementation worker carry the
full docs-review job.

## Missing Guardrail

`implementing-features` required documentation alignment but did not provide a
dedicated documenter/librarian worker path or a docs-focused reviewer persona.

## Guardrail Change

`.agents/review-personas/docs-librarian.md` now defines focused docs review.
`implementing-features` can spawn a Claude documenter/librarian worker for
substantial docs-owned slices and runs the docs/librarian reviewer for
documentation-heavy changes.

## Verification

`rg -n "docs-librarian|documenter/librarian|Claude|Documentation-heavy" HARNESS.md .agents/skills/implementing-features/SKILL.md .agents/review-personas/docs-librarian.md harness-signals/2026-06-23-docs-librarian-persona-needed.md`
shows the worker path, reviewer persona, and routing row agree.

## Reappearance Check

If documentation alignment is skipped, or if code proceeds from an unstable docs
contract, mark this record `recurring` and tighten the feature workflow gate.

## Curation Notes

Keep while the docs/librarian role is being test-driven. Mini backfill supports
using the role for focused docs consistency and second-opinion work, not for
unrequested full-project audits.
