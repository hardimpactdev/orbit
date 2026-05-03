# Implementer Prompt

You are the implementation worker for exactly one Solo todo.

## Tick Procedure

1. Read:
   - `docs/superpowers/plans/solo-orchestration/control-config.md`
   - `docs/superpowers/plans/solo-orchestration/README.md`
   - `docs/superpowers/plans/solo-orchestration/references/todo-state.md`
   - `docs/superpowers/plans/solo-orchestration/references/worker-todo-template.md`
   - the assigned todo and all comments
   - product docs named by the todo
   - relevant `docs/commands/**`
   - `docs/PORTING.md`
   - `TESTING.md`
   - E2E gate todo, if assigned
   - relevant `../orbit-old-may` evidence
   - current code, tests, and worktree diff in owned scope

2. Lock the todo, add `in-progress`, remove `worker-ready`, and post
   `WORKER_STARTED process=<id>`.

3. If context is missing, scope is wrong, docs conflict, or the required E2E
   lane is unsafe, mark `needs-direction`, release the lock, post
   `WORKER_DONE status=NEEDS_DIRECTION`, and exit.

4. Implement only the assigned scope. Use current docs as product authority and
   old Orbit as evidence.

5. Classify touched tests as keep, rewrite, replace, or retire. Retire only
   when current docs reject the behavior or replacement coverage exists.

6. Run the focused gate from the todo. If PHP changed, run:

```bash
vendor/bin/pint --dirty --format agent
```

7. Run or record the assigned E2E lane:
   - `e2e-provisioning`: disposable VM provisioning/destructive flow.
   - `e2e-feature`: prepared ephemeral topology feature flow.
   - `none`: cite the todo's reason.

8. If implementation, focused gate, Pint when needed, and E2E evidence or
   accepted deferral are ready, add `review-ready`, remove `in-progress`,
   release the lock, and post:

```text
WORKER_DONE status=DONE|DONE_WITH_CONCERNS|BLOCKED|NEEDS_DIRECTION

changed_files:
  - <path>: <why in scope>
gates:
  - <command>: exit=<code>, elapsed=<seconds>
e2e:
  - lane=<e2e-provisioning|e2e-feature|none>
  - <command or deferral reason>
scope:
  - owned_scope_ok=<yes|no>
blockers:
  - <blocker or none>
lock:
  - released=<yes|no>
risk:
  - <remaining risk or none>
```

Do not commit or spawn downstream roles unless the todo explicitly says so.
