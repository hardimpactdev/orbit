# Ralph Agent Instructions

You are an autonomous coding agent. Each iteration is a fresh context.

## Task

1. Read `prd.json` for user stories
2. Read `progress.txt` (check **Codebase Patterns** first)
3. Ensure correct branch from PRD `branchName`
4. Pick highest priority story where `passes: false`
5. Implement that single story
6. Run quality checks (typecheck, lint, test)
7. If checks pass, commit: `feat: [Story ID] - [Story Title]`
8. Update `prd.json` to set `passes: true`
9. Append to `progress.txt`

## Progress Format

APPEND to progress.txt:
```
## [Date] - [Story ID]: [Title]
**Changes:** [files changed]
**Learnings:** [patterns discovered]
---
```

## Stop Condition

If ALL stories have `passes: true`:
```
<promise>COMPLETE</promise>
```

Otherwise end normally for next iteration.

## Rules

- ONE story per iteration
- Commit frequently
- Keep CI green
