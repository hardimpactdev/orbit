# Signal: Raw Contract Dropped During Slicing

Status: guarded
First seen: 2026-06-24
Last seen: 2026-06-24
Last reviewed: 2026-06-24
Source worktree: doctor-progress-scheduler
Source commit: none
Signal type: agent-mistake
Guardrail target: HARNESS.md, .agents/skills/implementing-features/SKILL.md, .agents/review-personas/cli-command.md
Guardrail change: landed — HARNESS.md Done Contract raw-examples requirement; .agents/skills/implementing-features/SKILL.md raw acceptance examples and explicit deferrals; .agents/review-personas/cli-command.md raw-contract comparison
Related signals: harness-signals/2026-06-23-cli-ux-needs-pty-analysis-before-human-review.md, harness-signals/2026-06-23-loop-not-wired-to-implementation-skill.md
Superseded by: none
Tags: contract, slicing, prompt, cli, review

## Signal

During the doctor progress/fleet panel feature, the user provided concrete
expected output examples and later had to point out that the spawned feature
orchestrator prompt did not include all of that raw context. The implementation
then treated behavior visible in the raw examples as if it could be deferred or
discovered later by review.

This is a slicing failure, not merely a documentation gap. When an agent
decomposes a broad request, it must preserve the raw examples and explicitly
name any deferred parts before implementation starts.

## Prior Occurrences

Related CLI and review signals existed, but no dedicated record covered raw
request samples being lost while turning a user request into slices.

## Missing Guardrail

The Done Contract required objective, evidence, reviewer checks, stop
predicates, and pivot predicates, but it did not require raw output samples,
failure transcripts, screenshots, or negative examples to survive into
`.orbit/loop.md`, the feature scratchpad, or worker prompts. Reviewers also did
not have a clear instruction to compare implementation evidence against those
raw examples and classify mismatches as contract gaps.

## Guardrail Change

`HARNESS.md` now says concrete output samples, command transcripts, UI
examples, and negative examples belong in the Done Contract or a precise
pointer, and that decomposition must name current-slice scope, deferrals, and
why deferral does not invalidate acceptance.

`.agents/skills/implementing-features/SKILL.md` now requires feature owners and
worker prompts to preserve raw acceptance examples and explicit deferrals, and
to capture literal red-test output when tests are used as proof.

`.agents/review-personas/cli-command.md` now requires reviewers to read raw
examples and explicit deferrals, and to block mismatches with user-provided
samples unless they were explicitly deferred before implementation evidence was
produced.

## Verification

```bash
rg -n "raw user|Raw acceptance examples|Raw contract|explicit deferrals|user-provided output samples|negative examples" HARNESS.md .agents/skills/implementing-features/SKILL.md .agents/review-personas/cli-command.md harness-signals/2026-06-24-raw-contract-dropped-during-slicing.md
```

The search shows the guardrail is discoverable from the root harness, the
implementation workflow, the CLI reviewer persona, and this signal record.

## Reappearance Check

If a future implementation prompt omits concrete user examples or a reviewer
accepts a mismatch with a raw sample as a follow-up without a prior explicit
deferral, keep this record `recurring` and tighten the Done Contract template
or worker-prompt shape.

## Curation Notes

Keep separate from the PTY signal. PTY proof checks whether evidence was good;
this signal checks whether the requested behavior survived decomposition.
