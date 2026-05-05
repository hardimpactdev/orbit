# Self-Cleanup Sub-Procedure

## Inputs

- `target_todo`: the todo on which the role just posted its terminal label.
- `role`: the role name used in the `PROCESS_CLOSED` reason.

## Procedure

1. Resolve your own Solo process id by calling `whoami`.

2. On `target_todo`, post:

   ```text
   PROCESS_CLOSED process=<id> reason=<role>
   ```

3. Call `close_process` on your own process id.
