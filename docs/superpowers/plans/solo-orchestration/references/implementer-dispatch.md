# Implementer Dispatch Sub-Procedure

The orchestrator runs this sub-procedure synchronously and must not return
to its own next step until every candidate has reached a terminal outcome
(implementer dispatched, or workspace setup failed and skipped).

## Inputs

- `slots`: `concurrency.max_active_implementers` minus the count of live
  `IMPLEMENTER-*` processes.
- `coordination_todo`: from `control-config.md`.

## Procedure

1. If `slots <= 0`, return without dispatching.

2. Select up to `slots` candidate todos that are `worker-ready`, unblocked,
   unlocked, and not E2E gate todos. Order by `pipeline.dispatch_order`
   precedence, then by `docs/PORTING.md` order.

3. For each candidate, run steps 4–8 in parallel.

4. Inspect existing state for the candidate:
   - if a `WORKTREE_PREPARED` comment is present and no live
     `WORKTREE-SETUP-<todo_id>` process exists, skip to step 7;
   - if a live `WORKTREE-SETUP-<todo_id>` process exists, skip to step 6
     without spawning a second one;
   - otherwise, continue to step 5.

5. Spawn `agents.workspace_setup` named `WORKTREE-SETUP-<todo_id>` per
   `dispatch-protocol.md` with this prompt:

   ```text
   You are the Orbit Solo worktree setup helper. Read docs/superpowers/plans/solo-orchestration/references/worktree/setup.md and execute exactly once.

   Parameters:
   - todo_id: <todo_id>
   - branch: solo-<todo_id>
   - path: .worktrees/solo-<todo_id>
   - base_ref: main
   - coordination_todo: <coordination_todo>
   - product_docs: <docs named by todo>
   ```

6. Set a one-minute-interval Solo timer. On wake, re-inspect the candidate:
   - on `WORKTREE_PREPARED` comment, continue to step 7;
   - on `WORKTREE_SETUP_FAILED` comment, continue to step 8 with
     `reason=workspace-setup-failed`;
   - if the `WORKTREE-SETUP-<todo_id>` process has exited without either
     label, continue to step 8 with
     `reason=workspace-setup-process-exited`;
   - otherwise, set another one-minute idle timer and repeat step 6.

7. On `WORKTREE_PREPARED`: spawn `agents.implementation` named
   `IMPLEMENTER-<todo_id>` per `dispatch-protocol.md` with the worktree
   payload in context. Lock the todo, add the `in-progress` tag, remove
   the `worker-ready` tag, and post `WORKER_STARTED process=<id>` on the
   todo.

8. Leave the todo non-dispatched, then post on the todo:

   ```text
   WORKTREE_DISPATCH_SKIPPED reason=<workspace-setup-failed|workspace-setup-process-exited>
   ```

9. Return only after every candidate has resolved through step 7 or
   step 8.

The implementer is long-lived. It stays open after `WORKER_DONE`, receives
`send_input` feedback from later helpers, and is closed only by the
reconciler when its branch merges.
