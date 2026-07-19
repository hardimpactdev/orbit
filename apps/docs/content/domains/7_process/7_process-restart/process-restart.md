# `orbit process:restart [name]`

[Back to Process commands.](../README.md)

Restart one process, or all processes, in a node, app-instance, or workspace runtime
context.

`process:restart` cycles derived runtime units through the gateway on the owning
node and records lifecycle events for the transition.

## Usage

```bash
orbit process:restart vite --app=docs.production
orbit process:restart vite --app=docs.development --workspace=feature-docs
orbit process:restart opencode-server --node=app-dev-1
orbit process:restart vite --app=docs.production --json
orbit process:restart --app=docs.development --workspace=feature-docs
```

## Behavior Summary

Use this command to cycle one process or all processes for a resolved node, app-instance, or workspace context.

- **Context Resolution**: Resolves the node, app instance, or workspace runtime context. Prefer `<app.instance>`; a bare app slug is accepted only when that logical app has exactly one instance.
- **Placement**: App-instance and workspace runtime units are restarted on the instance's serving node.
- **Single Process**: When `[name]` is supplied, restarts that process only.
- **All Processes**: Omitting `[name]` restarts every process definition for the selected context in process order.
- **Runtime Effects**: Restarts the derived runtime units through the gateway and records lifecycle events for the stopped and started runtime transitions.
- **Configuration Unchanged**: Does not change process configuration and does not repair divergent runtime-unit files.

## Related

- [`process:start`](../5_process-start/process-start.md)
- [`process:stop`](../6_process-stop/process-stop.md)
- [`process-doctor.md`](../process-doctor.md)

***

**Technical Contract:** [`technical/1_process-restart.md`](technical/1_process-restart.md)
