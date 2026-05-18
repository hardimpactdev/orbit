# `orbit process:start [name]`

[Back to Process commands.](../README.md)

Start one process, or all processes, in an app or workspace runtime context.

`process:start` starts derived runtime units through the gateway on the owning
app node and records durable `started` events.

## Usage

```bash
orbit process:start vite --app=docs
orbit process:start vite --app=docs --workspace=feature-docs
orbit process:start vite --app=docs --json
orbit process:start --app=docs --workspace=feature-docs
```

## Behavior Summary

Use this command to start one process or all processes for a resolved app or workspace context.

- **Context Resolution**: Resolves the app or workspace runtime context.
- **Single Process**: When `[name]` is supplied, starts that process only.
- **All Processes**: When `[name]` is omitted, starts all process definitions for the selected context in process order.
- **Event Recording**: Records and publishes a `started` process event after each successful start.
- **Configuration Unchanged**: Does not change process configuration.

## Related

- [`process:stop`](../6_process-stop/process-stop.md)
- [`process:restart`](../7_process-restart/process-restart.md)
- [`process:logs`](../8_process-logs/process-logs.md)

***

**Technical Contract:** [`technical/1_process-start.md`](technical/1_process-start.md)
