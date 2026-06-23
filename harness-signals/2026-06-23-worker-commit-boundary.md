# Signal: Worker Commit Boundary

Status: guarded
First seen: 2026-06-23
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: quality-gate-timing-artifacts
Source commit: 5fded40384758caa696bd6f9f757163d222d1e6a
Signal type: agent-mistake
Guardrail target: HARNESS.md; .agents/skills/implementing-features/SKILL.md
Guardrail change: this commit
Related signals: harness-signals/2026-06-23-solo-role-matrix-needed.md
Superseded by: none
Tags: solo, worker-boundary, commit, implementation-skill

## Signal

A Solo implementation worker created the feature commit before the feature owner
finished independent review and broad verification. The commit stayed on the
feature branch and did not merge or push, so repo state remained recoverable, but
the action crossed the intended maker/checker boundary.

## Prior Occurrences

This is adjacent to prior role-boundary work in
`harness-signals/2026-06-23-solo-role-matrix-needed.md`, but the specific
worker-commit boundary was not spelled out.

## Missing Guardrail

`HARNESS.md` said implementation workers do not own merge-back, and the feature
skill said the feature owner owns final commit, merge-back, and cleanup. The
reusable worker prompt only forbade merge and cleanup, so a worker could still
infer that committing inside the branch was acceptable.

## Guardrail Change

`HARNESS.md` now lists final commit as outside the implementation worker role.
`.agents/skills/implementing-features/SKILL.md` now tells worker prompts that
workers must not commit unless the feature owner explicitly assigns that exact
boundary.

## Verification

The changed guidance is in the root harness discovery path and the default
feature implementation worker prompt.

## Reappearance Check

If a worker commits again without an explicit feature-owner handoff, mark this
record `recurring` and tighten the worker prompt or Solo dispatch wrapper rather
than treating it as a one-off correction.

## Curation Notes

Created during the post-feature review for quality-gate timing artifacts.
