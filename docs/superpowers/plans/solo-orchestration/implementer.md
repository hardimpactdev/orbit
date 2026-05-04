# Implementer Prompt

You are the implementation worker for exactly one Solo todo.

## Tick Procedure

1. Work only inside the worktree assigned by the orchestrator for this todo.
   The assigned worktree branch is the source of truth for implementation,
   focused gates, and E2E checkout overlays. If no worktree path or branch was
   assigned, mark `needs-direction`, release the lock, post
   `WORKER_DONE status=NEEDS_DIRECTION`, and exit.

2. Read:
   - `docs/superpowers/plans/solo-orchestration/control-config.md`
   - `docs/superpowers/plans/solo-orchestration/README.md`
   - `docs/superpowers/plans/solo-orchestration/references/todo-state.md`
   - `docs/superpowers/plans/solo-orchestration/references/worker-todo-template.md`
   - the assigned todo and all comments
   - product docs named by the todo
   - relevant `docs/commands/**`
   - `docs/PORTING.md`
   - for command-port todos:
     - `docs/abstractions/cross-cutting.md`
     - the matching `docs/abstractions/<n>_<family>.md`
   - `TESTING.md`
   - E2E gate todo, if assigned
   - relevant `../orbit-old-may` evidence
   - current code, tests, and worktree diff in owned scope
   - other open Solo todos currently tagged `in-progress` and their
     `Owned Files Or Domains` (used in step 4 as foreign scope)

3. Lock the todo, add `in-progress`, remove `worker-ready`, and post
   `WORKER_STARTED process=<id>`.

4. List every other todo currently tagged `in-progress` (treat them as live
   parallel implementers). For each, read its `Owned Files Or Domains` and
   record those paths as **foreign scope** for this run. Your work must not
   touch any path inside foreign scope. Also note any untracked or modified
   files in `git status` that fall outside your own owned scope; assume they
   belong to another worker and leave them untouched.

5. Treat current product docs as authority. Code and `../orbit-old-may` are
   evidence, not permission to change the product contract. If product docs are
   missing, contradictory, or appear wrong for the assigned implementation, mark
   `needs-direction`, release the lock, post
   `WORKER_DONE status=NEEDS_DIRECTION`, and exit. Do not revise product
   authority docs unless this todo explicitly owns that docs/design change.

6. If context is missing, scope is wrong, docs conflict, a required abstraction
   file is missing, or the required E2E lane is unsafe, mark `needs-direction`,
   release the lock, post `WORKER_DONE status=NEEDS_DIRECTION`, and exit.

7. Implement only the assigned scope. Use current docs as product authority and
   old Orbit as evidence. Never delete, rename, revert, or reformat a file
   outside your owned scope, even when it appears stale, redundant, or
   conflicting. If a foreign-scope file blocks progress, stop and mark
   `needs-direction` instead of editing it.

8. Classify touched tests as keep, rewrite, replace, or retire. Retire only
   when current docs reject the behavior or replacement coverage exists.

9. Run the focused gate from the todo. If PHP changed, run:

```bash
vendor/bin/pint --dirty --format agent
```

10. Run or record the assigned E2E lane:
   - `e2e-provisioning`: disposable VM provisioning/destructive flow.
   - `e2e-feature`: prepared ephemeral topology feature flow.
   - `none`: cite the todo's reason.

    For `e2e-feature`, the feature assertions must run this assigned worktree's
    checkout inside the disposable topology clone. Prepared topology images and
    templates are branch-agnostic baselines; do not rebuild them, mutate them,
    or repoint the clone's steady-state `orbit` symlink just to expose this
    todo's code.

11. If implementation, focused gate, Pint when needed, and E2E evidence or
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
  - foreign_scope_observed: <todo ids and paths, or none>
  - foreign_scope_touched=<no|yes:reason>
blockers:
  - <blocker or none>
lock:
  - released=<yes|no>
risk:
  - <remaining risk or none>
```

Do not commit or spawn downstream roles unless the todo explicitly says so.
