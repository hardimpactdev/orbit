# Signal: Bounded Reviewer Verdict Needed

Status: open
First seen: 2026-06-24
Last seen: 2026-06-24
Last reviewed: 2026-06-24
Source worktree: quality-check-updateall-pty-structure
Source commit: pending
Signal type: agent-mistake
Guardrail target: .agents/skills/implementing-features/SKILL.md
Guardrail change: none
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

## Prior Occurrences

Related records already cover reviewer workflow hooks and worker first-diff
discipline. They do not cover the case where a reviewer has enough context but
does not return a bounded accept/block verdict.

## Missing Guardrail

The implementation flow requires relevant reviewers, but it does not yet define
reviewer timeboxes, output shape, or fallback handling when a reviewer inspects
the right files but does not produce a verdict.

## Guardrail Change

None yet. A future guardrail should make reviewer prompts request a concise
accept/block verdict first, with optional details after, and should set a short
Solo timer for small changed-files-only reviews.

## Verification

The quality-gate stabilization proceeded with direct evidence instead:
focused quiet PTY test, full `UpdateAllCommandTest.php` profile,
`composer test`, `composer quality-check`, and `composer quality-gate:final-check`.

## Reappearance Check

If a future required reviewer takes too long on a small changed-files-only
review, interrupt once with a verdict-only prompt. If it still does not return,
close the reviewer, mark this signal recurring, and continue only if the feature
owner can defend the change from tests and direct inspection.

## Curation Notes

Keep while the quality-gate and reviewer persona loop is being hardened.
