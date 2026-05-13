# `orbit process:logs [name]`

[Back to Process commands.](../README.md)

**Purpose:** Show or follow logs for a process runtime context.

**Description:** Reads process logs from the owning node process manager through the gateway for a resolved app or workspace context.

**Technical contract:** [`technical/1_process-logs.md`](technical/1_process-logs.md)

## Usage

```bash
orbit process:logs vite --app=docs
orbit process:logs vite --app=docs --workspace=feature-docs --follow
orbit process:logs queue --app=docs --lines=200 --json
```

## Behavior

- Resolves the process and app/workspace runtime context.
- Streams or returns Supervisor logs through the gateway.
- Does not mutate process configuration.
- Uses JSON output only for non-follow mode. `--json --follow` is rejected before opening the log stream with `error.code=validation_failed`.

## Related

- [`process:list`](../4_process-list/process-list.md)
- [`process:start`](../5_process-start/process-start.md)
- [`process-doctor.md`](../process-doctor.md)
