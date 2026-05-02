# Tool Commands

Tool commands manage node capabilities Orbit installs, configures, observes,
and keeps converged. Tools are Orbit product concepts; package managers,
binaries, containers, and services are backend details.

## Domain Rules

- The tool command family owns the `tool:*` command prefix.
- `tool` is a state family. A gateway tool row is the expected state for one
  tool on one node.
- Tool rows include the node, tool name, expected lifecycle state, expected
  version or config when the tool definition tracks them, install paths, and
  backend-specific probe and repair settings.
- CLI callers resolve input locally, then the gateway reads or writes intent and
  performs node inspection or enactment.
- Some tools are observational, while others are managed by Orbit.
- Tool reads use gateway-tracked configuration by default. Live node status is
  included only when a command explicitly requests live inspection or when
  doctor runs.
- `tool:update` changes version intent or updates a managed tool to the latest
  supported version. It is not a generic setup rerun command.
- `tool:reconfigure` reruns a managed tool's configuration/setup flow without
  changing the intended version.
- `tool:reload` reloads configuration without a full restart only when the tool
  definition supports reload.
- Tools observed on a node without a gateway tool row are unmanaged inventory,
  not drift, unless explicit `doctor --family=tool --adopt` semantics are used.
- Role baseline tools are materialized as tool rows during node provisioning, so
  doctor has one gateway-owned source of truth per node.
- Node reality import is not part of the tool command surface. If an adoption
  flow needs to adopt node reality, it must use explicit
  `doctor --family=tool --adopt` semantics.
- Tools supply capabilities that other domains depend on, but they do not own
  apps, workspaces, processes, schedules, proxy routes, or firewall rules.

## Tool JSON Entity

Tool-family JSON renderers that return one tool entity embed this shape under
`success.data.tool`, or directly under `success.data.tools[]` for list items.
Command-specific outcome fields, log frames, or credential fields live beside
the entity in the command result.

```json
{
  "name": "redis",
  "node": "app-1",
  "expected_state": "running",
  "observed_state": "running",
  "version": "7.2",
  "managed": true
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `name` | string | Tool identity in Orbit's tool catalog. |
| `node` | string | Node slug where the tool is expected. |
| `expected_state` | string | Gateway-owned intended lifecycle state, such as `installed`, `running`, or `absent`. |
| `observed_state` | string \| null | Last known or live observed state when the command includes it. Registry reads may return `null`. |
| `version` | string \| null | Intended or observed version when the tool definition tracks versions. |
| `managed` | boolean | Whether Orbit owns lifecycle/configuration for this tool on the node. |

## Commands

1. [`orbit tool:list`](1_tool-list/tool-list.md)
2. [`orbit tool:show <tool>`](2_tool-show/tool-show.md)
3. [`orbit tool:install <tool>`](3_tool-install/tool-install.md)
4. [`orbit tool:remove <tool>`](4_tool-remove/tool-remove.md)
5. [`orbit tool:start <tool>`](5_tool-start/tool-start.md)
6. [`orbit tool:stop <tool>`](6_tool-stop/tool-stop.md)
7. [`orbit tool:restart <tool>`](7_tool-restart/tool-restart.md)
8. [`orbit tool:logs <tool>`](8_tool-logs/tool-logs.md)
9. [`orbit tool:update [tool]`](9_tool-update/tool-update.md)
10. [`orbit tool:credentials [tool]`](10_tool-credentials/tool-credentials.md)
11. [`orbit tool:reload [tool]`](11_tool-reload/tool-reload.md)
12. [`orbit tool:reconfigure [tool]`](12_tool-reconfigure/tool-reconfigure.md)

## Related

- [`doctor --family=tool`](tool-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
- Firewall rules, once the firewall command family is converted
