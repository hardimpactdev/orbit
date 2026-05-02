# `orbit process:stop [name]`

[Back to Process commands.](../README.md)

**Purpose:** Stop a process in an app or workspace runtime context.

**Description:** Stops one derived runtime unit through the gateway on the
owning app node and records a durable `stopped` event.

**Technical contract:** [`technical/1_process-stop.md`](technical/1_process-stop.md)

## Usage

```bash
orbit process:stop vite --app=docs
orbit process:stop vite --app=docs --workspace=feature-docs
orbit process:stop vite --app=docs --json
```

## Behavior

- Resolves the process and app/workspace runtime context.
- Stops the derived runtime unit through the gateway.
- Records and publishes a `stopped` process event after successful stop.
- Does not change process intent and does not remove the runtime unit.

## Related

- [`process:start`](../5_process-start/process-start.md)
- [`process:restart`](../7_process-restart/process-restart.md)
- [`process:logs`](../8_process-logs/process-logs.md)
