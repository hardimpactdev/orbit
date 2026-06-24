# Signal: Bounded Reviewer Verdict Needed

Status: recurring
First seen: 2026-06-24
Last seen: 2026-06-24
Last reviewed: 2026-06-24
Source worktree: quality-check-updateall-pty-structure; quality-e2e-lane-timing-baseline
Source commit: quality-e2e-lane-timing-baseline
Signal type: agent-mistake
Guardrail target: .agents/skills/implementing-features/SKILL.md
Guardrail change: quality-e2e-lane-timing-baseline
Related signals: harness-signals/2026-06-23-review-persona-needs-workflow-hook.md, harness-signals/2026-06-23-worker-first-diff-checkpoint.md
Superseded by: none
Tags: solo, review, claude, quality-gate, timing

## Signal

A Claude review agent was required for a quality-gate timing iteration. The
first Claude reviewer inspected the changed diff and related test body, then
spent more than a minute reasoning without returning a findings-first verdict.
A second Claude reviewer received a no-tools prompt with the exact diff and
helper contract, but also failed to return a bounded verdict in the allotted
window.

The code change itself was small and independently verified, but the review
lane did not produce timely value. A required reviewer that cannot return a
proportional verdict becomes a bottleneck in the loop.

The signal reappeared during `quality-e2e-lane-timing-baseline`: a Claude Opus
reviewer loaded the two changed E2E test files and then stayed in a long
reasoning step without returning a verdict. The feature owner interrupted and
closed it, then spawned a Claude Sonnet low-effort reviewer with a concise
blockers-first prompt. That second reviewer returned `No blockers` and useful
non-blocking assertion-hardening suggestions.

The same worktree exposed the opposite failure mode: Claude was again reading
files and generating, but the orchestrator was about to treat the short wait
window as a stall. After the reviewer was given several uninterrupted minutes,
it returned a detailed `NO BLOCKERS` verdict with accurate coverage and evidence
analysis. Active file reads, tool use, or generation should be treated as
productive review work, not as a reason to interrupt early.

The signal reappeared again after a rebase fix in
`quality-e2e-lane-timing-baseline`: a Claude reviewer was asked to review two
changed files, but chose `git diff main -- ...`, which expanded the review from
the uncommitted post-rebase fix into a broader branch comparison and then did
not return a verdict. A replacement Claude reviewer was given the exact command
`git diff HEAD -- bin/orbit-prepare-release-package
apps/gateway/tests/Feature/Release/OrbitReleaseWorkflowTest.php` and returned
`NO BLOCKERS`. For small post-review or post-rebase fixes, the prompt must name
the exact diff base and owned files.

## Prior Occurrences

Related records already cover reviewer workflow hooks and worker first-diff
discipline. They do not cover the case where a reviewer has enough context but
does not return a bounded accept/block verdict.

## Missing Guardrail

The implementation flow requires relevant reviewers, but it does not yet define
reviewer timeboxes, output shape, or fallback handling when a reviewer inspects
the right files but does not produce a verdict.

## Guardrail Change

`.agents/skills/implementing-features/SKILL.md` now instructs feature owners to
request a blockers-first verdict for required reviewer personas, bound small
changed-files-only reviews, give Claude-style reviewers minute-scale read time
when output shows active progress, interrupt once only after idle or extended
unproductive review, and replace or close the reviewer if the verdict still does
not arrive.

After the rebase-fix recurrence, the same skill also instructs feature owners to
include the exact diff command and base for small changed-files-only reviews,
such as `git diff HEAD -- <owned files>` for uncommitted post-review fixes,
instead of leaving the reviewer to choose a broader branch comparison.

## Verification

The quality-gate stabilization proceeded with direct evidence instead:
focused quiet PTY test, full `UpdateAllCommandTest.php` profile,
`composer test`, `composer quality-check`, and `composer quality-gate:final-check`.

## Reappearance Check

If a future required reviewer takes too long on a small changed-files-only
review, first distinguish active reading/generation from idle or waiting state,
then follow the skill guardrail. If exact diff commands still do not keep
reviews bounded, move the guardrail from skill prose into a reviewer-persona
prompt template or Solo timer helper.

## Curation Notes

Keep while the quality-gate and reviewer persona loop is being hardened.
