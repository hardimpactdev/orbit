# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: user-reported workspace env apply failure in this Codex task
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-workspace-env-worktree-apply
- Branch: codex/fix-workspace-env-worktree-apply

## Goal

Make `orbit workspace:env set --apply` safely write and apply environment values for the exact registered workspace path, including Orbit-managed `.worktrees/name` paths, restart the selected workspace runtime, and report phase-accurate JSON state.

## Scope

- Owned: workspace env command contract/docs; gateway workspace env controller/applier and runtime reapply; CLI internal env-file authorization, atomic writes, and regression tests.
- Constraints: TDD; exact registered-workspace containment including `.worktrees/name`; preserve traversal, arbitrary-root, and symlink protections; preserve unrelated env content and file permissions; idempotent repeated apply; phase-accurate errors and result booleans; live proof against the registered DLF `react` workspace.
- Out of scope: Dutch Laravel Foundation application source changes; unrelated Orbit cleanup; production workspace support; E2E Composer commands.

## Proof

- Verification:
  - focused: passed - scoped `readForApply` preserves legacy `read`; lane1 63 / lane2 187; evidence under `.orbit/evidence/green-scoped-readforapply-summary.md`
  - broader: passed - `ORBIT_QUALITY_CHECK_CPU_BUDGET=6 composer quality-check`; evidence `.orbit/evidence/workspace-env-quality-check.md`
  - runtime: passed - candidate=89322e6f9436dfa35cee08203a30203a6b2a24ce; venue=retained-incus; environment=dev-868c8e/operator_gateway_app-dev; command=orbit workspace:env set react --instance=envproof.development --key=REDIS_HOST --value=172.29.0.1 --apply --json; expected=registered .worktrees path is written atomically and the selected runtime restarts with accurate JSON; observed=repeated identical applies returned stored applied env_written and runtime_restarted true while preserving content hash mode and unrelated values and advancing only the workspace container start time; result=passed; evidence=`.orbit/evidence/workspace-env-retained-incus.md`
- Blast radius: complete - evidence=independent repository-wide consumer inventory and `git diff --check`; result=strict reads remain apply-only, runtime restart remains opt-in, legacy consumers retain their behavior, and output/path vocabulary has no unresolved consumers
- Review: passed - human-judgment=not-required; independent re-review found no actionable findings
- Reviewed feature tip: 89322e6f9436dfa35cee08203a30203a6b2a24ce
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 89322e6f9436dfa35cee08203a30203a6b2a24ce
- Accepted main tip: e977f6c06ae55968d6f2306f1ca1855f5c34e0db

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
