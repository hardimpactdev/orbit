# Signal: Raw Contract Dropped During Slicing

Status: recurring
First seen: 2026-06-24
Last seen: 2026-07-10
Last reviewed: 2026-07-10
Source worktree: doctor-progress-scheduler
Source commit: none
Signal type: agent-mistake
Guardrail target: HARNESS.md, .agents/skills/implementing-features/SKILL.md, .agents/review-personas/cli-command.md
Guardrail change: recurring — 2026-07-10 accepted design/panel adjudication preservation tightened in HARNESS.md Done Contract and .agents/skills/implementing-features/SKILL.md feature-owner, worker-handoff, and per-loop Reviewer checks
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

Analyzer 983: candidate A is missed. An accepted design/panel adjudication
against free-text analyzer verdict canonicalization was lost across
implementation/review handoffs; existing raw-example/deferral coverage does not
explicitly preserve accepted design/panel adjudications.

Claude 943: keep this in the current worktree; mark the existing raw-contract
signal recurring; minimally extend HARNESS Done Contract and
implementing-features worker handoff/Reviewer checks reachability; do not add
standing reviewer-persona prose.

Feature-owner final adjudication: three authored guardrail surfaces only:
HARNESS.md, .agents/skills/implementing-features/SKILL.md, and
harness-signals/2026-06-24-raw-contract-dropped-during-slicing.md. No
reviewer-persona edit and no new signal. harness-signals/index.json is accepted
solely as the owning tool's deterministic companion after the stale-index gate.

The replay-correction slice then repeated the same handoff loss at the action
boundary. A user-requested high-model review was still running, but the packet
kept a completion-ready `Loop outcome`; the implementation was merged and
archived before that review returned two required corrections. Claude 943 and
the feature owner adjudicated that an open user-requested review is part of the
accepted contract: the packet must keep `Loop outcome: blocked` and name the
review as a blocker until its findings are closed. The existing finalization
gate then prevents the merge without another guidance surface.

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

The guarded path also did not explicitly require accepted design/panel
adjudications to survive feature-owner and worker handoffs or require per-loop
Reviewer checks to compare implementation evidence against them when they
exist.

## Guardrail Change

`HARNESS.md` now says concrete output samples, command transcripts, UI
examples, and negative examples belong in the Done Contract or a precise
pointer, and that decomposition must name current-slice scope, deferrals, and
why deferral does not invalidate acceptance.

`.agents/skills/implementing-features/SKILL.md` now requires feature owners and
worker prompts to preserve raw acceptance examples and explicit deferrals, and
to capture literal red-test output when tests are used as proof.

After recurrence, `HARNESS.md` also requires accepted design/panel
adjudications to be preserved verbatim or by a precise pointer alongside raw
examples and deferrals. The implementing-features feature-owner and worker
handoffs carry those adjudication lines or pointer, and the per-loop Done
Contract Reviewer checks explicitly compare implementation evidence against
them when they exist. No standing reviewer-persona ceremony was added.

`.agents/review-personas/cli-command.md` now requires reviewers to read raw
examples and explicit deferrals, and to block mismatches with user-provided
samples unless they were explicitly deferred before implementation evidence was
produced.

## Verification

```bash
rg -n "raw user|Raw acceptance examples|Raw contract|explicit deferrals|user-provided output samples|negative examples" HARNESS.md .agents/skills/implementing-features/SKILL.md .agents/review-personas/cli-command.md harness-signals/2026-06-24-raw-contract-dropped-during-slicing.md
rg -ni "accepted (design|adjudication)|design/panel adjudications|accepted adjudication" HARNESS.md .agents/skills/implementing-features/SKILL.md harness-signals/2026-06-24-raw-contract-dropped-during-slicing.md
```

The searches show the original raw-contract guardrail remains discoverable and
the accepted-adjudication tightening is discoverable from the root harness, the
implementation workflow, and this signal record.

## Reappearance Check

If a future implementation prompt omits concrete user examples or a reviewer
accepts a mismatch with a raw sample as a follow-up without a prior explicit
deferral, keep this record `recurring` and tighten the Done Contract template
or worker-prompt shape. Apply the same recurrence rule when accepted
design/panel adjudication lines or their precise pointer disappear across a
handoff, or when per-loop Reviewer checks fail to compare against them.
Also keep this record `recurring` when a user-requested review gate is recorded
but `Loop outcome` becomes completion-ready before that review and its
corrections are closed; the required state is `blocked`, with the open review
named in `## Blockers`.

## Curation Notes

Keep separate from the PTY signal. PTY proof checks whether evidence was good;
this signal checks whether the requested behavior survived decomposition.
