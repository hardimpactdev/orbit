# Signal: Handoff Needs Next Step

Status: guarded
First seen: 2026-06-23
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: doctor-panel-human-rendering
Source commit: none
Signal type: review-comment
Guardrail target: HARNESS.md, .agents/skills/implementing-features/SKILL.md
Guardrail change: current handoff guardrail slice
Related signals: none
Superseded by: none
Tags: handoff, reporting, loop-engineering

## Signal

The user pointed out that completion reports should include the next slice or
next concrete step. The orchestrator should not make the user ask what comes
after a finished loop-engineering slice.

## Prior Occurrences

No prior durable signal record existed. This appeared while test-driving the
root harness slices and reviewer persona flow.

## Missing Guardrail

The implementation report shape had follow-up fields, but it did not force the
agent to name the immediate next step after summarizing completed work.

## Guardrail Change

`HARNESS.md` now includes handoff as part of the loop stack. The
`implementing-features` report shape now includes an explicit `Next step`
section.

## Verification

`rg -n "Next step|Handoff|Do not make the user ask" HARNESS.md .agents/skills/implementing-features/SKILL.md harness-signals/2026-06-23-handoff-needs-next-step.md`
shows the guardrail is discoverable from the root harness, implementation
report template, and signal ledger.

## Reappearance Check

If a future completion report omits the next slice or next concrete step, mark
this record `recurring` and tighten the relevant report template or reviewer
persona.

## Curation Notes

Keep while the loop-engineering handoff style settles.
