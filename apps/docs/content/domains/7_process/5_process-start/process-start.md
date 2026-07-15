# `orbit process:start [name]`

[Back to Process commands.](../README.md)

Start one process, or all processes, in a node, app-instance, or workspace runtime
context.

`process:start` starts derived runtime units through the gateway on the owning
node and records durable `started` events.

## Usage

```bash
orbit process:start vite --app=docs.production
orbit process:start vite --app=docs.production --workspace=feature-docs
orbit process:start opencode-server --node=app-dev-1
orbit process:start vite --app=docs.production --json
orbit process:start --app=docs.production --workspace=feature-docs
```

## Behavior Summary

Use this command to start one process or all processes for a resolved node, app-instance, or workspace context.

- **Context Resolution**: Resolves the node, app instance, or workspace runtime context. Prefer `<app.instance>`; a bare app slug is accepted only when that logical app has exactly one instance.
- **Placement**: App-instance and workspace runtime units are started on the instance's serving node.
- **Single Process**: When `[name]` is supplied, starts that process only.
- **All Processes**: Omitting `[name]` starts every process definition for the selected context in process order.
- **Event Recording**: Records and publishes a `started` process event after each successful start.
- **Configuration Unchanged**: Does not change process configuration.

## Related

- [`process:stop`](../6_process-stop/process-stop.md)
- [`process:restart`](../7_process-restart/process-restart.md)
- [`process:logs`](../8_process-logs/process-logs.md)

***

**Technical Contract:** [`technical/1_process-start.md`](technical/1_process-start.md)
