# Signal: Review Persona Needs Workflow Hook

Status: guarded
First seen: 2026-06-23
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: doctor-panel-human-rendering
Source commit: none
Signal type: review-comment
Guardrail target: .agents/skills/implementing-features/SKILL.md
Guardrail change: current reviewer-persona initiation slice
Related signals: harness-signals/2026-06-23-json-envelope-assumptions.md, harness-signals/2026-06-23-loop-not-wired-to-implementation-skill.md
Superseded by: none
Tags: reviewer-persona, implementing-features, workflow

## Signal

The first CLI reviewer persona existed and was discoverable through root
routing, but the implementation workflow did not yet explicitly initiate it.
That made the persona too passive: future agents could skip it unless they
manually inferred the handoff path.

## Prior Occurrences

This mirrors the earlier signal where the manual loop existed but was not wired
into the implementation skill. The same pattern reappeared for reviewer
personas.

## Missing Guardrail

`HARNESS.md` named the persona as the reviewer for CLI command changes, but
`.agents/skills/implementing-features/SKILL.md` did not require the Codex
orchestrator to run the applicable persona after implementation evidence exists.

## Guardrail Change

The implementation skill now says the orchestrator runs applicable reviewer
personas from `HARNESS.md`. For CLI command changes, it explicitly names
`.agents/review-personas/cli-command.md` before accepting the slice. The report
shape also includes a reviewer-persona result line.

## Verification

`rg -n "reviewer persona|cli-command.md|Reviewer personas" .agents/skills/implementing-features/SKILL.md harness-signals/2026-06-23-review-persona-needs-workflow-hook.md`
shows the initiation path is reachable.

## Reappearance Check

If a future implementation skips an applicable reviewer persona, mark this
record `recurring` and move the persona invocation into a stricter pre-commit
check or reviewer gate.

## Curation Notes

Keep while reviewer personas are being introduced one at a time.
