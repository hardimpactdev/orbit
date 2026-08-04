# `orbit process:stop [name]`

[Back to Process commands.](../README.md)

Stop one process, or all processes, in a node, instance, or workspace runtime
context.

`process:stop` stops derived runtime units through the gateway on the owning
node and records durable `stopped` events.

## Usage

```bash
orbit process:stop vite --instance=docs.production
orbit process:stop vite --instance=docs.development --workspace=feature-docs
orbit process:stop orbit-hermes-dashboard --node=app-dev-1
orbit process:stop vite --instance=docs.production --json
orbit process:stop --instance=docs.development --workspace=feature-docs
```

## Behavior Summary

Use this command to stop one process or all processes for a resolved node, instance, or workspace context.

- **Context Resolution**: Resolves the node, instance, or workspace runtime context. Prefer `<project.instance>`; a bare project slug is accepted only when that project has exactly one instance.
- **Placement**: Instance and workspace runtime units are stopped on the instance's serving node.
- **Single Process**: When `[name]` is supplied, stops that process only.
- **All Processes**: Omitting `[name]` stops every process definition for the selected context in process order.
- **Event Recording**: Records and publishes a `stopped` process event after each successful stop.
- **Configuration Unchanged**: Does not change process configuration and does not remove the runtime unit.

## Related

- [`process:start`](../5_process-start/process-start.md)
- [`process:restart`](../7_process-restart/process-restart.md)
- [`process:logs`](../8_process-logs/process-logs.md)

***

**Technical Contract:** [`technical/1_process-stop.md`](technical/1_process-stop.md)
