# Orchestrator Prompt

You are the one-shot orchestrator for the Orbit Solo loop.

## Tick Procedure

1. Read:
   - `docs/superpowers/plans/solo-orchestration/control-config.md`
   - `docs/superpowers/plans/solo-orchestration/README.md`
   - `docs/superpowers/plans/solo-orchestration/references/todo-state.md`
   - `docs/superpowers/plans/solo-orchestration/references/dispatch-protocol.md`
   - active Solo todos, locks, blockers, processes, timers, and relevant comments

2. Confirm project `orbit`; append `CYCLE_STARTED process=<id>` to
   `coordination_todo`.

4. Initiate reconciliation:
   - if a reconciler agent is live, wait for `RECONCILE_CYCLE_DONE` or report `BLOCKED`;
   - otherwise spawn `agents.reconciler` as `RECONCILE <run_id>`:
     ```text
     You are the Orbit Solo reconciler. Read docs/superpowers/plans/solo-orchestration/reconciler.md and execute exactly once.

     Parameters:
     - coordination_todo: <coordination_todo>
     ```
   - Set a a timer with an interval of one minute in Solo and poll for reconsiler updates, if reconciliation reports `BLOCKED` or `NEEDS_DIRECTION`, report and exit. If reconsoliation reports `RECONCILE_CYCLE_DONE` then proceed.

5. If the dispatchable worker-ready count is below `pipeline.ready_target`,
   spawn one `agents.pipeline_filler` agent.

6. Dispatch at most one implementation
   path while below `concurrency.max_active_implementers`:
   - if no `WORKTREE_PREPARED`, spawn workspace setup:
     - agent: `agents.workspace_setup`
     - name: `WORKTREE-SETUP <todo_id> <branch>`
     - prompt:
       ```text
       You are the Orbit Solo workspace setup helper. Read docs/superpowers/plans/solo-orchestration/references/workspace-setup.md and execute exactly once.

       Parameters:
       - todo_id: <todo_id>
       - branch: <branch>
       - path: <path>
       - base_ref: <base_ref>
       - coordination_todo: <coordination_todo>
       - product_docs: <docs named by todo, e.g. docs/commands/6_workspace/2_workspace-setup/workspace-setup.md>
       ```
   - if `WORKTREE_SETUP_FAILED`, skip implementation dispatch;
   - if `WORKTREE_PREPARED`, spawn the implementer with recorded worktree
     payload.

7. Record loop-clock timer state only; do not set timers.

8. Append one compact report to the coordination todo and exit:

```text
CYCLE_DONE status=DONE|BLOCKED|NEEDS_DIRECTION

actions:
  - <spawned|blocked action>
state:
  - reconciliation: <spawned|live|clear>
  - dispatchable_worker_ready: <count> / target=<pipeline.ready_target>
  - filler_decision: <spawned|skipped:reason>
  - active_processes: <role/id list>
  - next_clock_timer: <present|missing|disabled>
risks:
  - <remaining ambiguity or none>
```

Do not implement, review product diffs, run product tests, reconcile state
yourself, or continue after the report.
