# `orbit process:list`

[Back to Process commands.](../README.md)

List process configuration and last known runtime state for a node, app instance, or
workspace.

`process:list` reads process definitions and latest durable lifecycle events
from the gateway without performing live node inspection.

## Usage

```bash
orbit process:list --app=docs.production
orbit process:list --app=docs.production --workspace=feature-docs
orbit process:list --node=app-dev-1
orbit process:list --app=docs.production --json
```

## Behavior Summary

Use this command to inspect process configuration and last known state without live node probing.

- **Process Definitions**: Reads process definitions from gateway configuration.
- **Context Resolution**: Resolves a node, app instance, or workspace context. Prefer `<app.instance>`; a bare app slug is accepted only when that logical app has exactly one instance. A workspace context includes workspace-owned definitions and app-instance-owned definitions inherited by that workspace.
- **Placement**: App-instance and workspace contexts read runtime identities from the instance's serving node.
- **Runtime Identity**: Derives expected runtime unit identities for that context.
- **Service Metadata**: Includes safe connection metadata for service process
  definitions, including endpoint and credential field names but not credential
  values.
- **Lifecycle Events**: Shows the latest durable process lifecycle event when one exists.
- **No Live Probing**: Does not SSH to the owning node and does not run live runtime probes.

## Related

- [`process:logs`](../8_process-logs/process-logs.md)
- [`process:start`](../5_process-start/process-start.md)
- [`process-doctor.md`](../process-doctor.md)

***

**Technical Contract:** [`technical/1_process-list.md`](technical/1_process-list.md)
