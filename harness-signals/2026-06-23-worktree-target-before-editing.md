# Signal: Worktree Target Must Be Confirmed Before Editing

Status: recurring
First seen: 2026-06-19
Last seen: 2026-06-24
Last reviewed: 2026-06-24
Source worktree: one-time historical Codex backfill
Source commit: none
Signal type: agent-mistake
Guardrail target: HARNESS.md, LOOP.md.example, .agents/skills/implementing-features/SKILL.md
Guardrail change: current root routing and loop-state slice
Related signals: harness-signals/2026-06-23-loop-not-wired-to-implementation-skill.md
Superseded by: none
Tags: worktree, ownership, implementation

## Signal

Historic Orbit sessions show that agents can inspect the right worktree but
still edit from the wrong checkout, or report from `main` while the reviewable
work is in `.worktrees/<branch>`.

## Prior Occurrences

This appeared during previous feature work where a first patch landed in the
primary checkout before the agent corrected back to the dedicated worktree. It
also reappeared in this loop-engineering test drive when review context became
hard to follow across `main` and the feature worktree.

It reappeared during quality-gate lane tuning when Solo `spawn_agent` launched
Codex at the project root even though the worker prompt named the active
worktree. The orchestrator stopped those workers and relaunched Codex through
Solo terminals that first `cd` into the worktree.

## Missing Guardrail

The agent knew worktrees were required, but the active worktree and branch were
not a first-class contract field that had to be confirmed before editing.

## Guardrail Change

`HARNESS.md` now includes a root goal contract and routing table. `LOOP.md.example`
requires the active worktree and branch, and the implementation skill requires
editing only inside the assigned worktree. After the 2026-06-24 recurrence, the
harness and implementation skill require every worktree-scoped Solo worker to
prove `pwd` and `git branch --show-current` before broad reads or edits. If the
agent opens in the project root, relaunch it through a Solo terminal that first
`cd`s into the worktree.

## Verification

`rg -n "Worktree|Branch|Edit only inside|bin/orbit-prepare-worktree" HARNESS.md LOOP.md.example .agents/skills/implementing-features/SKILL.md`
shows the pre-edit worktree target is discoverable.

## Reappearance Check

If this happens again after the hard pre-edit check, tighten the worker prompt
template further or add a small wrapper command for worktree-scoped Solo agent
launches.

## Curation Notes

Keep while the root harness is being test-driven through real worktrees.
