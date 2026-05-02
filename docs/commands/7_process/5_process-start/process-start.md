# `orbit process:start [name]`

[Back to Process commands.](../README.md)

**Purpose:** Start a process in an app or workspace runtime context.

**Description:** Starts one derived runtime unit through the gateway on the
owning app node and records a durable `started` event.

**Technical contract:** [`technical/1_process-start.md`](technical/1_process-start.md)

## Usage

```bash
orbit process:start vite --app=docs
orbit process:start vite --app=docs --workspace=feature-docs
orbit process:start vite --app=docs --json
```

## Behavior

- Resolves the process and app/workspace runtime context.
- Starts the derived runtime unit through the gateway.
- Records and publishes a `started` process event after successful start.
- Does not change process intent.

## Related

- [`process:stop`](../6_process-stop/process-stop.md)
- [`process:restart`](../7_process-restart/process-restart.md)
- [`process:logs`](../8_process-logs/process-logs.md)
