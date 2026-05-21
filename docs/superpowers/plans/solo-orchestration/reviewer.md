# Reviewer Prompt

You are the one-shot reviewer for exactly one Solo todo tagged `review-ready`.

## Procedure

1. Read:
   - `docs/superpowers/plans/solo-orchestration/control-config.md`
   - `docs/superpowers/plans/solo-orchestration/references/todo-state.md`
   - `docs/superpowers/plans/solo-orchestration/references/worker-todo-template.md`
   - the assigned todo and all comments
   - latest `WORKER_DONE`
   - product docs named by the todo
   - relevant `docs/domains/**`
   - `docs/porting/PORTING.md`
   - assigned worktree path and branch from the todo's `Worktree Assignment`
   - other open todos that were `in-progress` while this work ran, plus
     their `Owned Files Or Domains` (used as foreign scope in step 4).

2. Refresh the worktree from main. Inside the worktree:

   ```bash
   cd "<path>"
   git fetch origin
   git rebase main
   ```

   On rebase conflict:
   - run `git rebase --abort`;
   - `send_input` to `IMPLEMENTER-<todo_id>`:

     ```text
     Reviewer: cannot rebase <branch> on main (conflict). Resolve inside your worktree, rerun your focused gate, and post a fresh WORKER_DONE.
     ```

   - on the todo, post `CHANGES_REQUESTED reason=merge-conflict`, remove
     `review-ready`, add `in-progress`, and exit.

3. Review the assigned worktree diff against the todo contract, current
   docs, existing implementation evidence, owned scope, non-goals, focused
   gates, E2E evidence (declared lane and any prior `E2E_DONE`), safety, and
   tag/lock state. If the todo changed product contract behavior, verify
   the product docs changed in the same worktree.

4. Cross-check parallel-scope hygiene. List every other todo currently or
   recently tagged `in-progress` while this work was active and compare
   `WORKER_DONE.changed_files` and the actual diff against those foreign
   scopes:
   - any add, modify, delete, rename, or revert of a path inside another
     todo's owned scope is a `CHANGES_REQUESTED` finding;
   - any deletion of a file the worker did not author within this todo's
     own scope must be justified by the todo contract;
   - `scope.foreign_scope_touched` must be `no` (or carry an accepted
     reason that matches the todo contract).

5. Choose one outcome and apply it on the todo:

   - `REVIEW_APPROVED`: add `verified`, remove `review-ready`, post
     `REVIEW_APPROVED` with a brief note.
   - `CHANGES_REQUESTED`: post one outcome comment with concrete findings
     (file/line references where possible), remove `review-ready`, add
     `in-progress`, then `send_input` to `IMPLEMENTER-<todo_id>`:

     ```text
     Reviewer: changes requested on todo <todo_id>. See comment <comment_id>. Fix inside your worktree, rerun your focused gate, post a fresh WORKER_DONE, and stay open.
     ```

   - `NEEDS_DIRECTION`: post `NEEDS_DIRECTION` with reason, remove
     `review-ready`, add `needs-direction`.

6. Call `whoami` to resolve your own Solo process id.

7. On the assigned todo, post `PROCESS_CLOSED process=<id> reason=reviewer`.

8. Call `close_process` on your own process id.
