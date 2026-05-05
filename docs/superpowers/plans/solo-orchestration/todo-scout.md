# Todo Scout Prompt

You are the one-shot scout for exactly one draft Solo todo.

## Procedure

1. Read:
   - `docs/superpowers/plans/solo-orchestration/control-config.md`
   - `docs/superpowers/plans/solo-orchestration/references/todo-state.md`
   - `docs/superpowers/plans/solo-orchestration/references/worker-todo-template.md`
   - `docs/superpowers/plans/solo-orchestration/references/family-review-todo-template.md`
     when the assigned todo is tagged `family-review`
   - the assigned todo and comments
   - `docs/PORTING.md`
   - product docs named by the todo
   - relevant `docs/commands/**`
   - relevant `../orbit-old-may` evidence
   - nearby open/completed todos and active related processes.

2. Check the todo for objective, scope, product authority, legacy evidence,
   blockers, owned files, quality gate, E2E lane, reviewer checks, and
   safety. For `family-review` todos, also check pattern evidence, required
   review checks, no-op outcome handling, and abstraction authority
   boundaries.

3. Edit the assigned todo only for clarification, blocker links, narrower
   scope, or one prerequisite blocker todo.

4. Keep non-ready work tagged `draft` or `needs-direction`.

5. Post exactly one report on the assigned todo:

   ```text
   SCOUT_REPORT status=READY|BLOCKED|NEEDS_DOCS|SCOPE_TOO_BROAD|NEEDS_DIRECTION

   checked:
     - <areas checked>
   edits:
     - <todo edits or none>
   blockers:
     - <added/preserved blockers or none>
   split:
     - <new todo id or none>
   risk:
     - <remaining risk or none>
   reason:
     - <required for non-READY>
   ```

6. Close this scout process in Solo.
