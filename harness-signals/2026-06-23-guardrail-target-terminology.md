# Signal: Guardrail Target Terminology Was Clearer Than Sink

Status: guarded
First seen: 2026-06-23
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: codex/root-harness-anchor-review-ui
Source commit: b269f590
Signal type: review-comment
Guardrail target: LOOP.md.example, HARNESS_SIGNALS.md
Guardrail change: 3eecf1c4
Related signals: none
Superseded by: none
Tags: terminology, harness-signals, loop-engineering

## Signal

The initial feedback-loop docs used "sink" as the destination for a distilled
signal. The user pointed out that loop engineering is about creating guardrails,
and that "guardrail target" better communicates both guidance and prevention.

## Prior Occurrences

No prior durable signal record existed. This was the first terminology
correction in the root harness slice.

## Missing Guardrail

The docs used systems terminology that was compact but not legible enough for
agents and humans reviewing the harness.

## Guardrail Change

`LOOP.md.example`, `HARNESS_SIGNALS.md`, and the `HARNESS.md` discovery path now
use "guardrail target" instead of "sink".

## Verification

`rg -n "sink|Sink|source-to-sink|signal-to-target" HARNESS.md LOOP.md.example HARNESS_SIGNALS.md`
returned no matches, and `composer docs-lint` exited 0.

## Reappearance Check

If "sink" reappears in harness docs, treat it as terminology drift and replace
it unless the context is unrelated to the feedback-loop model.

## Curation Notes

Keep while the harness vocabulary is settling. Retire once later worktrees show
the terminology is no longer drifting.
