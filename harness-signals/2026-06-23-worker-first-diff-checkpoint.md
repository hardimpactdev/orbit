# Signal: Worker First Diff Checkpoint

Status: guarded
First seen: 2026-06-23
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: quality-gate-final-check
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

## Verification

`rg -n "first narrow diff|broad discovery without a first diff" .agents/skills/implementing-features/SKILL.md harness-signals/2026-06-23-worker-first-diff-checkpoint.md`
shows the checkpoint is discoverable from the worker prompt, the feature-owner
monitoring flow, and this signal.

The focused quality-gate artifact tests and `composer quality-check` passed in
the same worktree after the guardrail update.

## Reappearance Check

If a future worker with a clear owned slice keeps searching without producing a
first diff or explicit missing-context blocker, mark this record `recurring` and
tighten the Solo dispatch wrapper or require a timed first-checkpoint response.

## Curation Notes

Keep while Solo worker delegation is still being hardened. Consolidate into the
Solo role matrix record only if future work makes first-diff behavior part of a
broader worker lifecycle contract.
