# `orbit process:restart [name]`

[Back to Process commands.](../README.md)

Restart one process, or all processes, in a node, instance, workspace, or app
hostname runtime context.

`process:restart` cycles derived runtime units through the gateway on the owning
node and records durable lifecycle events (`restarting` before the runtime call,
then `started` on success or `failed` on backend failure).

## Usage

```bash
orbit process:restart vite --instance=docs.production
orbit process:restart vite --instance=docs.development --workspace=feature-docs
orbit process:restart vite --app=test.app.example
orbit process:restart orbit-hermes-dashboard --node=app-dev-1
orbit process:restart vite --instance=docs.production --json
orbit process:restart --instance=docs.development --workspace=feature-docs
```

## Behavior Summary

Use this command to cycle one process or all processes for a resolved node, instance, workspace, or app hostname context.

- **Context Resolution**: Resolves the node, instance, workspace, or `--app`
  hostname runtime context. Prefer `<app.instance>`; a bare app slug is
  accepted only when that app has exactly one instance. `--app` is mutually
  exclusive with `--node`, `--instance`, and `--workspace`.
- **Placement**: Instance, workspace, and app-hostname runtime units are restarted on the instance's serving node.
- **Single Process**: When `[name]` is supplied, restarts that process only.
- **All Processes**: Omitting `[name]` restarts every process definition for the selected context in process order.
- **Runtime Effects**: Restarts the derived runtime units through the gateway and
  records a transitional `restarting` event before each runtime call, then a
  terminal `started` event on success or `failed` when the backend returns false
  or throws.
- **Configuration Unchanged**: Does not change process configuration and does not repair divergent runtime-unit files.

## Related

- [`process:start`](../5_process-start/process-start.md)
- [`process:stop`](../6_process-stop/process-stop.md)
- [`process-doctor.md`](../process-doctor.md)

***

**Technical Contract:** [`technical/1_process-restart.md`](technical/1_process-restart.md)
