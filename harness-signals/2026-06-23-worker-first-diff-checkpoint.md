# Signal: Worker First Diff Checkpoint

Status: recurring
First seen: 2026-06-23
Last seen: 2026-06-24
Last reviewed: 2026-06-24
Source worktree: quality-gate-final-check; quality-gate-e2e-artifacts; quality-gate-baselines; quality-check-updateall-pty-structure; quality-check-cli-pest-pty-speed
Source commit: pending
Signal type: agent-mistake
Guardrail target: .agents/skills/implementing-features/SKILL.md
Guardrail change: current worktree
Related signals: harness-signals/2026-06-23-solo-role-matrix-needed.md, harness-signals/2026-06-23-worker-commit-boundary.md
Superseded by: none
Tags: solo, worker-boundary, implementation-skill, discovery

## Signal

A Solo Codex implementation worker received a narrow worktree, ownership set,
TDD instruction, and verification contract for the quality-gate final-check
slice. It read the expected guidance, then continued broad discovery and did
not produce the first focused test diff after two correction prompts. The
feature owner had to stand the worker down and implement the slice directly.

## Prior Occurrences

Related records already cover the Solo role matrix and worker commit boundary.
They did not cover the case where a worker has the right role and ownership but
still spends too long in discovery before making the first owned change.

This signal reappeared in `quality-gate-e2e-artifacts`: a Solo Codex worker
received the worktree, hard stops, owned files, and a first-checkpoint contract,
then continued reading optional skill and reference material instead of
producing the focused test diff. After an explicit correction, it acknowledged
the boundary but continued reading named files and still produced no diff. The
feature owner stopped the worker and implemented the slice directly.

This signal reappeared again in `quality-gate-baselines`: a Solo Codex worker
received an even narrower quality-gate slice with an explicit first checkpoint,
but still read extra memory and global skill context before producing any test
diff. After the first-diff correction, the worker remained without a diff or
blocker and was stood down.

This signal reappeared again in `quality-check-updateall-pty-structure`: the
first Solo Codex worker read broad local and global context after a narrow
two-line cadence-fix handoff. After a correction it still read more files
without producing a diff. A replacement Codex worker repeated the pattern until
the feature owner sent an exact patch instruction after interruption. The
replacement then produced the intended two-line diff and verification evidence.

This signal reappeared again in `quality-check-cli-pest-pty-speed`: the Solo
Codex worker received a strict first-diff checkpoint for a narrow CLI Pest
timing slice, identified the intended test seam, then stalled without producing
a diff or exact missing-context blocker. The feature owner stood the worker
down and treated the stalled attempt as a documented loop exception.

## Missing Guardrail

The reusable worker prompt required narrow ownership and TDD, but it did not
define the first checkpoint after reading required files. A worker could keep
searching without either producing the first narrow diff or reporting missing
context.

## Guardrail Change

`.agents/skills/implementing-features/SKILL.md` now tells implementation
workers to produce the first narrow diff in the owned test, docs, or code
surface after reading the required local files. If that first diff cannot be
made from the handoff, the worker must report the missing context instead of
continuing broad discovery.

The feature-owner monitoring step now treats broad discovery without a first
diff as a process problem to correct.

After the recurrence, `.agents/skills/implementing-features/SKILL.md` also
requires the feature owner to stand down the worker after one explicit
first-diff correction if the worker still reads or reasons without producing a
diff or blocker. Do not allow a stalled worker to consume the slice.

After the second recurrence, the next guardrail should stop relying on prompt
wording alone. Feature owners should dispatch the first checkpoint as a
test-only patch with a short timer, then replace the worker immediately if it
does not return a diff or missing-context blocker.

After the third recurrence, the first-checkpoint contract should become an
operational gate rather than just prompt wording: the feature owner should set a
short Solo timer for the first diff and treat any extra discovery after that
timer as failure unless the worker reports an exact missing-context blocker.

## Verification

`rg -n "test-only first diff|short timer|replace the worker|first narrow diff|broad discovery without a first diff|stand down the worker" .agents/skills/implementing-features/SKILL.md harness-signals/2026-06-23-worker-first-diff-checkpoint.md`
shows the checkpoint is discoverable from the worker prompt, the feature-owner
monitoring flow, and this signal.

The focused quality-gate artifact tests and `composer quality-check` passed in
the same worktree after the guardrail update.

In `quality-check-updateall-pty-structure`, the replacement worker eventually
produced the intended two-line test diff. The feature owner reran the focused
quiet PTY test, the full `UpdateAllCommandTest.php` profile, `composer test`,
`composer quality-check`, and `composer quality-gate:final-check`.

## Reappearance Check

If a future worker with a clear owned slice keeps searching without producing a
first diff or explicit missing-context blocker after one correction, do not keep
prompting. Stand down the worker and require a timed test-only first-checkpoint
response before assigning substantial implementation.

If the task is a known tiny patch, the feature owner may include the exact patch
shape in the first-checkpoint prompt. If the worker still reads broadly, close
it and either replace it or apply the patch directly as a documented loop
exception.

## Curation Notes

Keep while Solo worker delegation is still being hardened. Consolidate into the
Solo role matrix record only if future work makes first-diff behavior part of a
broader worker lifecycle contract.
