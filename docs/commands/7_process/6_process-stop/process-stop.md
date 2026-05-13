# `orbit process:stop [name]`

[Back to Process commands.](../README.md)

**Purpose:** Stop one process, or all processes, in an app or workspace runtime context.

**Description:** Stops derived runtime units through the gateway on the owning app node and records durable `stopped` events.

**Technical contract:** [`technical/1_process-stop.md`](technical/1_process-stop.md)

## Usage

```bash
orbit process:stop vite --app=docs
orbit process:stop vite --app=docs --workspace=feature-docs
orbit process:stop vite --app=docs --json
orbit process:stop --app=docs --workspace=feature-docs
```

## Behavior

- Resolves the app or workspace runtime context.
- When `[name]` is supplied, stops that process only.
- When `[name]` is omitted, stops all process definitions for the selected context in process order.
- Records and publishes a `stopped` process event after each successful stop.
- Does not change process configuration and does not remove the runtime unit.

## Related

- [`process:start`](../5_process-start/process-start.md)
- [`process:restart`](../7_process-restart/process-restart.md)
- [`process:logs`](../8_process-logs/process-logs.md)
