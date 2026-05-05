# Worktree Merge Sub-Procedure

Used by the reconciler to fast-forward one verified todo's branch into `main`
and clean up its worktree and branch.

## Inputs

- `todo`: the queued implementation todo, including its `Worktree Assignment`
  (`path`, `branch`).
- `coordination_todo`: from `control-config.md`.

## Procedure

1. Read the todo's `Worktree Assignment` to resolve `path` and `branch`.

2. Verify the diff against the todo's `Owned Files Or Domains` and that any
   product contract change cited by the todo is present in the diff. If the
   diff includes paths outside owned scope, follow
   `docs/superpowers/plans/solo-orchestration/references/worktree/merge-conflict-handling.md`
   under "Scope mismatch" and stop processing this todo.

3. From the main Orbit repo, fast-forward `main`:

   ```bash
   git switch main
   git pull --ff-only origin main || true
   git merge --ff-only "<branch>"
   ```

4. If `git merge --ff-only` fails, follow the matching rule in
   `docs/superpowers/plans/solo-orchestration/references/worktree/merge-conflict-handling.md`
   and stop processing this todo.

5. On success, clean up the worktree and branch:

   ```bash
   git worktree remove "<path>"
   git branch -d "<branch>"
   ```

6. On the todo, post:

   ```text
   RECONCILE_DONE status=MERGED path=<path> branch=<branch>
   ```

   Then post `ORCHESTRATOR_CLOSED` and complete the todo.

7. Close the long-lived implementer for this todo:

   - find the live process named `IMPLEMENTER-<todo_id>`;
   - on the todo, post `PROCESS_CLOSED process=<id> reason=implementer`;
   - call `close_process`.
