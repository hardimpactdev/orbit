# Multi-Slice Feature Scratchpad Must Exist Before Dispatch

Status: guarded
Date: 2026-06-24
Source worktree: mago-baseline-cli-activity-normalization
Guardrail target: AGENTS.md; HARNESS.md; LOOP.md.example; .agents/skills/implementing-features/SKILL.md

## Signal

During the Mago baseline cleanup dogfood run, the feature orchestrator started a
multi-slice feature from a source scratchpad in NMBP project 4, created
`.orbit/loop.md`, prepared the Mini worktree, and spawned an implementation
worker before creating a Mini project feature roadmap scratchpad.

The user had to point out that a feature too large for one slice should have a
scratchpad with the slice roadmap. The orchestrator then created
`solo://proj/2/scratchpad/mago-baseline-contra--380` and linked it from the
active `.orbit/loop.md`.

## Impact

Without a reachable feature roadmap scratchpad, `.orbit/loop.md` can become a
hidden substitute for feature history even though it is supposed to be
current-slice state only. This makes handoff, slice boundaries, and post-feature
distillation weaker, especially when execution happens on a different machine or
Solo project from the source handoff.

## Guardrail

Root and skill guidance now make the scratchpad a pre-dispatch gate:

- multi-slice feature work must create or identify the feature roadmap
  scratchpad before worktree prep or worker dispatch;
- `.orbit/loop.md` must link the roadmap and remain active-slice state;
- when execution moves to another Solo project or machine, the execution
  project gets its own reachable roadmap scratchpad that links the source.

## Verification

```bash
rg -n "pre-dispatch gate|execution-project roadmap|feature roadmap scratchpad|Active slice start gate|Missing a feature scratchpad" AGENTS.md HARNESS.md LOOP.md.example .agents/skills/implementing-features/SKILL.md
```

## Reappearance Check

If a future multi-slice implementation reaches worker dispatch without a linked
feature roadmap scratchpad, treat the slice as off-loop and correct it before
accepting implementation work. If the rule is still missed after this guardrail,
consider adding a mechanical finalization check that blocks merge when
`.orbit/loop.md` names multiple slices but has no `solo://` scratchpad link.
