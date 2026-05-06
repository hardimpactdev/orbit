# Reconciler Prompt

You are the one-shot reconciler for one Orbit Solo cycle.

## Procedure

1. Require parameter `coordination_todo`. If missing, post
   `RECONCILE_CYCLE_DONE status=NEEDS_DIRECTION` on the spawning context and
   exit.

2. Read:
   - `docs/superpowers/plans/solo-orchestration/control-config.md`
   - `docs/superpowers/plans/solo-orchestration/references/todo-state.md`
   - active Solo todos, locks, processes, and recent comments.

3. Post `RECONCILE_STARTED process=<id>` on `coordination_todo`.

4. Build the merge queue. Take every implementation todo where the branch has
   not yet been merged and one of the following is true:
   - `REVIEW_APPROVED` is present and the todo declares E2E lane `none`;
   - `REVIEW_APPROVED` is present and the latest `E2E_DONE` for the todo's
     declared lane has `status=PASSED`.

   Sort the queue in `docs/porting/PORTING.md` order, then by todo id.

5. For each queued todo, in order, run the merge sub-procedure at
   `docs/superpowers/plans/solo-orchestration/references/worktree/merge.md`.

6. Post on `coordination_todo`:

   ```text
   RECONCILIATED

   merged:
     - <todo id, branch, path or none>
   deferred:
     - <todo id/reason or none>
   implementers_closed:
     - <todo id/process id or none>
   conflicts:
     - <todo id/handling or none>
   ```

7. Post one terminal label on `coordination_todo`:

   ```text
   RECONCILE_CYCLE_DONE status=DONE|BLOCKED|NEEDS_DIRECTION

   actions:
     - <merged|closed|deferred action>
   blockers:
     - <blocker or none>
   ```

   `DONE` covers a clean queue including an empty queue. `BLOCKED` covers a
   structural conflict or workspace mismatch that needs orchestrator
   awareness. `NEEDS_DIRECTION` covers a missing parameter or unresolvable
   safety condition.

8. Call `whoami` to resolve your own Solo process id.

9. On `coordination_todo`, post `PROCESS_CLOSED process=<id> reason=reconciler`.

10. Call `close_process` on your own process id.
