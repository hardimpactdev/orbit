# Signal: Codex Hook Best-Effort Finalization Check

Status: guarded
First seen: 2026-06-24
Last seen: 2026-06-24
Last reviewed: 2026-06-25
Source worktree: mago-baseline-cli-activity-normalization on Mini
Source commit: 6c0e30296 Normalize activity gateway responses
Signal type: agent-mistake
Guardrail target: HARNESS.md, AGENTS.md, .agents/skills/implementing-features/SKILL.md, .codex/hooks.json, bin/orbit-codex-pre-tool-use-hook, bin/orbit-feature-finalization-check
Guardrail change: explicit finalization gate and Codex hook dogfood hardening
Related signals: harness-signals/2026-06-23-loop-not-wired-to-implementation-skill.md
Superseded by: none
Tags: finalization, codex-hook, merge-boundary, loop-engineering

## Signal

A feature orchestrator intentionally tried to dogfood the merge-boundary guard
while `.orbit/loop.md` still had pending final-distillation state. The primary
Mini checkout was on `main` and ran:

```bash
git merge mago-baseline-cli-activity-normalization
```

Expected: the Orbit Codex hook blocks the merge.
Actual: the command fast-forwarded `main` to `6c0e30296`.

Direct hook invocation with the same command payload did block, so the PHP gate
could parse the command, find the linked feature worktree, and detect pending
finalization. The failure was that Codex did not invoke the hook for the shell
execution surface used by the agent. A second issue was found during diagnosis:
the hook exited `1`, while Codex documents exit code `2` as the blocking status
for `PreToolUse`.

## Why It Matters

The loop relied too heavily on a best-effort Codex hook. Codex `PreToolUse`
hooks are useful guardrails, but current Codex support does not intercept every
newer shell execution path. A feature owner can therefore merge or clean up a
worktree without the hook firing, losing `.orbit/` evidence before distillation.

## Guardrail Change

- `bin/orbit-codex-pre-tool-use-hook` now exits `2` when blocking.
- `.codex/hooks.json` uses `matcher: "*"` so any supported `PreToolUse`
  invocation can reach the same narrow gate.
- `bin/orbit-feature-finalization-check` provides an explicit pre-merge and
  pre-cleanup command that does not depend on hook interception.
- `HARNESS.md`, `AGENTS.md`, and the implementation skill now say the explicit
  finalization check is required and the Codex hook is best-effort.
- The finalization check intentionally treats the exact
  `LOOP.md.example` outcome list labels as the contract. Equivalent custom
  headings such as `Harness Signals`, bare label lines without `- ` and `:`, or
  prose such as `accepted/promoted by parent loop` are not enough unless one of
  the required labels records the outcome.
- The gate permits historical final-distillation prose that mentions a past
  pending state. It blocks placeholders and missing/non-meaningful outcome
  values, not every occurrence of words like `pending` in the evidence summary.
- Hook status is not accepted as enforcement proof. `.codex/hooks.json` being
  present and `/hooks` showing an installed/active `PreToolUse` hook are only
  diagnostics. The hook dogfood passes only when a plain Codex-issued merge or
  cleanup boundary is blocked before Git runs.
- Use a valid, non-destructive command for future hook dogfood. Prefer
  `git branch -d <feature-branch>` while the branch is still checked out in its
  retained worktree, because Git should refuse the branch deletion if the hook
  misses. Invalid commands can prove the hook missed the tool call, but they are
  weaker evidence.

## Follow-Up Dogfood Evidence

During the Mago baseline contract cleanup dogfood handoff, Mini Codex process
1915 opened `/hooks` and saw `PreToolUse` installed and active. The process was
not launched with `--dangerously-bypass-hook-trust`. It then created a
disposable worktree from `origin/main` with no `.orbit/loop.md`:

```bash
bin/orbit-prepare-worktree hook-dogfood-incomplete-cleanup-1915 --base=origin/main --path=.worktrees/hook-dogfood-incomplete-cleanup-1915 --skip-tests
```

From the Mini primary checkout, the orchestrator issued a plain shell command:

```bash
git worktree remove --dry-run .worktrees/hook-dogfood-incomplete-cleanup-1915
```

The Codex hook did not block. Git ran and printed `error: unknown option
dry-run`, leaving the disposable worktree present. Direct invocation of
`bin/orbit-codex-pre-tool-use-hook` and `bin/orbit-feature-finalization-check`
with the same cleanup target both blocked with exit code `2`. That proves the
gate logic is working and the missed enforcement is in the Codex hook execution
surface, not the Orbit finalization parser.

This follow-up also tightened the dogfood contract: future hook probes should
use valid commands with Git-side safety rather than invalid options, and active
hook status must never replace a blocked plain command as proof.

## Verification

```bash
bin/orbit-codex-pre-tool-use-hook-test
php -l bin/orbit-codex-pre-tool-use-hook
rg -n "orbit-feature-finalization-check|best-effort|Merge Boundary Gate" AGENTS.md HARNESS.md .agents/skills/implementing-features/SKILL.md .codex/hooks.json bin/orbit-codex-pre-tool-use-hook-test harness-signals
```

The hook test covers JSON hook input, exit code `2`, and direct
`bin/orbit-feature-finalization-check git merge --ff-only <branch>` blocking.
It also covers the false-positive case where a completed final distillation
mentions historical pending-state evidence.

## Reappearance Check

If a future feature merges or cleans up with pending final-distillation state,
first ask whether `bin/orbit-feature-finalization-check` was run explicitly.
Do not assume the Codex hook should have caught it. If the explicit check was
skipped, tighten the implementation skill or merge workflow. If the explicit
check ran and allowed an incomplete feature, tighten the PHP gate logic and add
a test case for the missed command shape.

If a future loop claims the hook was verified, inspect the evidence. It must
show a plain Codex-issued boundary command that was stopped before Git ran. Hook
configuration, `/hooks` status, or direct script invocation alone are not enough.

## Curation Notes

Reviewed in the 2026-06-25 uniqueness pass. Keep separate from
`harness-signals/2026-06-23-loop-not-wired-to-implementation-skill.md`: that
record covers the broad loop/final-distillation workflow, while this record
covers the narrower hook-interception failure mode and explicit boundary-check
contract.
