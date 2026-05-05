# Implementer Dispatch Sub-Procedure

The orchestrator delegates to this procedure when filling implementer slots
in step 9 of `orchestrator.md`.

## Inputs

- `slots`: `concurrency.max_active_implementers` minus the count of live
  `IMPLEMENTER-*` processes.
- `coordination_todo`: from `control-config.md`.

## Procedure

1. If `slots <= 0`, stop.

2. Select up to `slots` candidate todos that are `worker-ready`, unblocked,
   unlocked, and not E2E gate todos. Order by `pipeline.dispatch_order`
   precedence, then by `docs/PORTING.md` order.

3. For each candidate, run steps 4–6 in parallel.

4. If the todo has no `WORKTREE_PREPARED` comment and no live
   `WORKTREE-SETUP-<todo_id>` process, spawn `agents.workspace_setup` named
   `WORKTREE-SETUP-<todo_id>` per `dispatch-protocol.md` with this prompt:

   ```text
   You are the Orbit Solo workspace setup helper. Read docs/superpowers/plans/solo-orchestration/references/workspace-setup.md and execute exactly once.

   Parameters:
   - todo_id: <todo_id>
   - branch: solo-<todo_id>
   - path: .worktrees/solo-<todo_id>
   - base_ref: main
   - coordination_todo: <coordination_todo>
   - product_docs: <docs named by todo>
   ```

5. Set a one-minute Solo idle timer and poll the todo for
   `WORKTREE_PREPARED` or `WORKTREE_SETUP_FAILED`. If
   `WORKTREE_SETUP_FAILED`, leave the todo non-dispatched and skip the
   remaining steps for this todo.

6. On `WORKTREE_PREPARED`, spawn `agents.implementation` named
   `IMPLEMENTER-<todo_id>` per `dispatch-protocol.md` with the worktree
   payload in context. Lock the todo, add the `in-progress` tag, remove the
   `worker-ready` tag, and post `WORKER_STARTED process=<id>` on the todo.
   Fire-and-forget.

The implementer is long-lived. It stays open after `WORKER_DONE`, receives
`send_input` feedback from later helpers, and is closed only by the
reconciler when its branch merges.
