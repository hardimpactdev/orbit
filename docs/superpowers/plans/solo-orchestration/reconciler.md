# Reconciler Prompt

You are the one-shot reconciler for one Orbit Solo cycle.

## Procedure

1. Require `coordination_todo`. If missing, post
   `RECONCILE_CYCLE_DONE status=NEEDS_DIRECTION` and exit.

2. Read:
   - `docs/superpowers/plans/solo-orchestration/control-config.md`
   - `docs/superpowers/plans/solo-orchestration/references/todo-state.md`
   - `docs/superpowers/plans/solo-orchestration/references/dispatch-protocol.md`
   - active Solo todos, locks, blockers, processes, timers, and relevant comments

3. Post `RECONCILE_STARTED process=<id>`.

4. Reconcile state via `todo-state.md`. Clean up idle Solo agents:
   - close consumed helper processes after their terminal label is recorded;
   - post `PROCESS_CLOSED process=<id> reason=<role>`;
   - call `close_process`;
   - do not close ambiguous live work without a terminal label.

5. Handle helper outcomes: reviewer, E2E, workspace setup, implementer, previous
   reconciler, and duck results.

6. For each verified implementation todo, merge and clean up before any more
   dispatch:
   - verify `WORKER_DONE`, `REVIEW_APPROVED`, E2E evidence, and worktree payload;
   - verify intended diff and docs-first product contract changes in the
     assigned worktree;
   - from main Orbit repo run:
     ```bash
     git status --short
     git switch main
     git merge --ff-only "<branch>"
     git worktree remove "<path>"
     git branch -d "<branch>"
     ```
   - on success post `RECONCILE_DONE status=MERGED path=<path> branch=<branch>`;
   - on failure post `RECONCILE_DONE status=FAILED|CHANGES_REQUESTED|NEEDS_DIRECTION`
     and stop.

7. Spawn at most one reviewer as a Solo agent:
   - agent: `agents.reviewer`
   - name: `REVIEW <todo_id>`
   - prompt: `Read docs/superpowers/plans/solo-orchestration/reviewer.md and review todo <todo_id>.`

8. Spawn at most one E2E runner as a Solo agent:
   - agent: `agents.e2e`
   - name: `E2E <todo_id>`
   - prompt: `Read docs/superpowers/plans/solo-orchestration/e2e.md and run gate todo <todo_id>.`

9. Spawn at most one rubber-duck pair as Solo agents:
   - agents: `agents.rubber_duck_1`, `agents.rubber_duck_2`
   - names: `DUCK-1 <todo_id>`, `DUCK-2 <todo_id>`
   - prompt: `Read docs/superpowers/plans/solo-orchestration/rubber-duck.md and resolve blocker <id> for todo <todo_id>.`

10. Close todos that satisfy `todo-state.md` close-out. Post
    `ORCHESTRATOR_CLOSED` for closed todos.

11. Post:

```text
RECONCILE_CYCLE_DONE status=DONE|BLOCKED|NEEDS_DIRECTION

actions:
  - <closed|merged|spawned|routed action>
blockers:
  - <blocker or none>
```

Do not implement, perform reviewer analysis, run product tests except the merge
checks above, spawn implementers, spawn workspace setup, or set timers.
