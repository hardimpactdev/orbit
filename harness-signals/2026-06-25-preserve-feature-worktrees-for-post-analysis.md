# Signal: Preserve Feature Worktrees For Post-Analysis

Status: guarded
First seen: 2026-06-25
Last seen: 2026-06-25
Last reviewed: 2026-06-25
Source worktree: post-merge review of Mini database and Vite TLS bugfix worktrees
Source commit: 0968bf322871a4eb4ae45baacb2354af9b4cbd2a
Signal type: agent-mistake
Guardrail target: AGENTS.md, HARNESS.md, .agents/skills/implementing-features/SKILL.md, bin/orbit-feature-finalization-check
Guardrail change: merge finalization now preserves completed feature worktrees and branches until post-feature signal audit
Related signals: harness-signals/2026-06-23-loop-not-wired-to-implementation-skill.md, harness-signals/2026-06-24-codex-hook-best-effort-finalization-check.md
Superseded by: none
Tags: finalization, worktrees, post-feature-review, loop-engineering

## Signal

After three Mini-driven bugfix todos landed, the completed worktrees had already
been removed. That made it harder to double-check `.orbit/loop.md`, local
evidence, reviewer output, terminal artifacts, and harness-signal adjudication
before moving to the next feature.

The user clarified that merge completion and worktree cleanup are separate
boundaries. A feature can land in `main`, but the completed worktree and branch
should remain available until post-feature signal audit confirms no durable
loop signal was missed, or until the user explicitly approves cleanup.

## Prior Occurrences

This is related to earlier finalization and loop-distillation signals, but it is
not the same failure. The earlier records required final distillation before
merge or cleanup. This record adds the post-merge preservation rule so the
orchestrator can audit the feature loop after the code has landed.

## Missing Guardrail

The root guidance still treated merge-back and worktree cleanup as one
finalization step. That allowed agents or finalization scripts to remove the
worktree immediately after merge, discarding local `.orbit/` context before the
loop improver could verify that scratchpad findings, reviewer corrections, and
candidate harness signals were processed.

## Guardrail Change

- `AGENTS.md` now says completed feature worktrees and branches are preserved
  after merge until post-feature signal audit is complete or the user explicitly
  approves cleanup.
- `HARNESS.md` now separates merge, post-feature signal audit, and worktree
  cleanup boundaries.
- `.agents/skills/implementing-features/SKILL.md` now makes the implementation
  report preserve-worktree status explicit.
- `bin/orbit-feature-finalization-check` help text now says cleanup checks run
  only after post-feature signal audit or explicit user approval.

## Verification

```bash
bash -n bin/orbit-feature-finalization-check
bin/orbit-feature-finalization-check 2>&1 | sed -n '1,44p'
rg -n 'post-feature signal audit|preserve the completed|Worktree cleanup' AGENTS.md HARNESS.md .agents/skills/implementing-features/SKILL.md bin/orbit-feature-finalization-check harness-signals
```

These checks prove the helper remains syntactically valid and that root
discovery, harness guidance, the implementation skill, the helper usage text,
and this signal record all expose the preservation rule.

## Reappearance Check

If a future completed feature worktree is removed before signal audit, first
check whether the user explicitly approved cleanup. If not, inspect whether the
feature owner read the current implementation skill and whether the cleanup
command passed through `bin/orbit-feature-finalization-check`. Tighten the
helper gate if it cannot distinguish approved cleanup from automatic cleanup.

## Curation Notes

Keep this separate from the Codex hook signal. The hook signal covers best-effort
interception and explicit boundary checks; this signal covers preserving local
feature context after a successful merge so post-feature loop analysis remains
possible.
