# Loop Initiation

Run this procedure whenever the user asks to start or resume the Solo
orchestration loop, or when coordination state is corrupt, duplicated,
missing, or unreadable.

## Procedure

1. Read `docs/superpowers/plans/solo-orchestration/control-config.md`.

2. Search Solo for any existing coordination todo from prior runs and close
   each one.

3. Create a new coordination todo following
   `docs/superpowers/plans/solo-orchestration/references/coordination-todo-template.md`.

4. Set `coordination_todo` in `control-config.md` to `<coordination_todo_id>`

5. Start or resume exactly one loop-clock agent named `LOOP-CLOCK <run_id>`.

6. Send the loop-clock this prompt exactly:

   ```text
   You are the Orbit Solo loop clock. Read docs/superpowers/plans/solo-orchestration/loop-clock.md before any other action.
   ```
