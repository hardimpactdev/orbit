# `orbit process:start [name]`

[Back to Process commands.](../README.md)

Start one process, or all processes, in a node, instance, workspace, or app
hostname runtime context.

`process:start` starts derived runtime units through the gateway on the owning
node and records durable lifecycle events (`starting` before the runtime call,
then `started` on success or `failed` on backend failure).

## Usage

```bash
orbit process:start vite --instance=docs.production
orbit process:start vite --instance=docs.development --workspace=feature-docs
orbit process:start vite --app=test.app.example
orbit process:start orbit-hermes-dashboard --node=app-dev-1
orbit process:start vite --instance=docs.production --json
orbit process:start --instance=docs.development --workspace=feature-docs
```

## Behavior Summary

Use this command to start one process or all processes for a resolved node, instance, workspace, or app hostname context.

- **Context Resolution**: Resolves the node, instance, workspace, or `--app`
  hostname runtime context. Prefer `<project.instance>`; a bare project slug is
  accepted only when that project has exactly one instance. `--app` is mutually
  exclusive with `--node`, `--instance`, and `--workspace`.
- **Placement**: Instance, workspace, and app-hostname runtime units are started on the instance's serving node.
- **Single Process**: When `[name]` is supplied, starts that process only.
- **All Processes**: Omitting `[name]` starts every process definition for the selected context in process order.
- **Event Recording**: Records and publishes a transitional `starting` process
  event before each runtime call, then a terminal `started` event on success or
  `failed` when the backend returns false or throws.
- **Configuration Unchanged**: Does not change process configuration.

## Related

- [`process:stop`](../6_process-stop/process-stop.md)
- [`process:restart`](../7_process-restart/process-restart.md)
- [`process:logs`](../8_process-logs/process-logs.md)

***

**Technical Contract:** [`technical/1_process-start.md`](technical/1_process-start.md)
