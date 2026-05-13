# `orbit process:start [name]`

[Back to Process commands.](../README.md)

**Purpose:** Start one process, or all processes, in an app or workspace runtime context.

**Description:** Starts derived runtime units through the gateway on the owning app node and records durable `started` events.

**Technical contract:** [`technical/1_process-start.md`](technical/1_process-start.md)

## Usage

```bash
orbit process:start vite --app=docs
orbit process:start vite --app=docs --workspace=feature-docs
orbit process:start vite --app=docs --json
orbit process:start --app=docs --workspace=feature-docs
```

## Behavior

- Resolves the app or workspace runtime context.
- When `[name]` is supplied, starts that process only.
- When `[name]` is omitted, starts all process definitions for the selected context in process order.
- Records and publishes a `started` process event after each successful start.
- Does not change process configuration.

## Related

- [`process:stop`](../6_process-stop/process-stop.md)
- [`process:restart`](../7_process-restart/process-restart.md)
- [`process:logs`](../8_process-logs/process-logs.md)
