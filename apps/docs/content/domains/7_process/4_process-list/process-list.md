# `orbit process:list`

[Back to Process commands.](../README.md)

List process configuration and last known runtime state for a node, instance,
workspace, or app hostname.

`process:list` reads process definitions and latest durable lifecycle events
from the gateway without performing live node inspection.

## Usage

```bash
orbit process:list --instance=docs.production
orbit process:list --instance=docs.development --workspace=feature-docs
orbit process:list --app=app.example
orbit process:list --app=test.app.example
orbit process:list --node=app-dev-1
orbit process:list --instance=docs.production --json
```

## Behavior Summary

Use this command to inspect process configuration and last known state without live node probing.

- **Process Definitions**: Reads process definitions from gateway configuration.
- **Context Resolution**: Resolves a node, instance, workspace, or `app`
  hostname context. Prefer `<project.instance>` for instance mode; a bare
  project slug is accepted only when that project has exactly one instance. A
  workspace context includes workspace-owned definitions and instance-owned
  definitions inherited by that workspace. `--app` accepts an app-instance or
  workspace hostname resolved through exact registered proxy-route domain precedence
  and cannot be combined with `--node`, `--instance`, or `--workspace`.
- **Placement**: Instance, workspace, and app-hostname contexts read runtime identities from the instance's serving node.
- **Runtime Identity**: Derives expected runtime unit identities for that context.
- **Service Metadata**: Includes safe connection metadata for service process
  definitions, including service identifier, version family, concrete version,
  endpoint, and credential field names but not credential values. Human output
  renders service, version, and endpoint for every managed service process.
- **Lifecycle Events**: Shows the latest durable process lifecycle event when one exists.
- **Status**: Each list item includes a concrete `status` normalized from the
  latest durable event (`running`, `stopped`, `crashed`, or `unknown` when no
  event exists).
- **No Live Probing**: Does not SSH to the owning node and does not run live runtime probes.

## Related

- [`process:logs`](../8_process-logs/process-logs.md)
- [`process:start`](../5_process-start/process-start.md)
- [`process-doctor.md`](../process-doctor.md)

***

**Technical Contract:** [`technical/1_process-list.md`](technical/1_process-list.md)
