# Codex Hook Best-Effort Finalization Check

Date: 2026-06-24
Status: guarded
Source worktree: mago-baseline-cli-activity-normalization on Mini
Source commit: 6c0e30296 Normalize activity gateway responses
Guardrail target: HARNESS.md, AGENTS.md, .agents/skills/implementing-features/SKILL.md, .codex/hooks.json, bin/orbit-codex-pre-tool-use-hook, bin/orbit-feature-finalization-check

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

## Guardrail

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
