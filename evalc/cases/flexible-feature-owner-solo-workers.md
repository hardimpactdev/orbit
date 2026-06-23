# Case: Flexible Feature Owner With Solo Workers

## Input Request

"Implement this feature from the Codex app. Use Solo for any subagents and for
retained terminal verification."

## Expected Workflow

- Treats the active LLM surface as the feature owner, whether it is Codex CLI,
  the Codex app, Claude, or another capable app.
- Creates a goal contract and isolated worktree.
- Uses Solo for spawned workers and retained verification terminals.
- Chooses worker roles from the root Solo role matrix.
- Gives each worker a clear ownership boundary, stop predicates, and reporting
  shape.
- Keeps merge, release, live topology, and final acceptance authority with the
  feature owner and user approval boundaries.

## Expected Evidence

- Worktree path and branch.
- Goal contract.
- Worker plan with Solo process ids or an explicit "not used" decision.
- Reviewer or verification lanes selected from `HARNESS.md`.
- Final reconciliation report from the feature owner.

## Forbidden Mistakes

- Claiming feature orchestration must happen inside Solo.
- Spawning untracked background model work outside Solo.
- Treating subagents as merge owners.
- Hiding worker output or accepting it without diff and evidence review.

## Grading Rubric

- Pass: Orchestration surface is flexible, while workers and terminals are
  tracked through Solo with clear ownership.
- Partial: Uses Solo, but worker ownership or approval boundaries are vague.
- Fail: Hard-codes one app as the orchestrator or bypasses Solo for subagents.
