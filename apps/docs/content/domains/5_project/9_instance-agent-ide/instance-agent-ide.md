# `orbit instance:agent-ide [project.instance] [agent_ide]`

[Back to Project and instance commands.](../README.md)

Set the agent IDE adapter for one instance.

Stores the instance-level agent IDE used by that instance and its workspaces.
Use it when one instance should differ from its serving-node default for messages,
process crash notifications, and workspace source discovery.

## Usage

```bash
orbit instance:agent-ide [project.instance] [agent_ide] [--force] [--json]
```

## Examples

```bash
orbit instance:agent-ide my-app.development opencode
orbit instance:agent-ide my-app.development inherit
orbit instance:agent-ide my-app.development none --force
orbit instance:agent-ide my-app.development polyscope --json
```

## Arguments and options

- `instance`: dotted instance selector. Bare logical shorthand succeeds only for
  exactly one eligible visible instance. Hostnames are invalid.
- `agent_ide`: agent IDE input value. Core adapter names are `opencode` and
  `polyscope`. Installed Orbit extensions may register additional adapters with
  the gateway. `inherit` and `none` are reserved app-scope tokens, not
  adapters: `inherit` clears the instance override so the effective adapter
  resolves through `instance → serving node → none`, while `none` disables
  agent IDE resolution for the instance and its workspaces.
- `--force`: skip the destructive consent prompt during adapter switches that
  trigger workspace removal.
- `--json`: Output JSON.

## What Happens

Run `instance:agent-ide` to set, clear, or disable the adapter for one instance.

`instance:agent-ide` writes the adapter into gateway instance configuration.

An `app-prod` caller cannot run this command because adapter switches may plan
workspace cleanup. A setting stored for an `app-prod` target applies only to
the app main context; production workspace discovery and cleanup never run.

The command:

1. Resolves one concrete instance and authorizes its serving node.
2. Validates that the requested adapter is supported.
3. When switching away from a previous effective adapter, identifies
   workspaces owned by the instance and absent under the new adapter. This
   operation requires explicit consent (`--force` or interactive confirmation)
   before any configuration is written.
4. Stores the adapter as the instance-level override.
5. Removes stale workspaces through `instance:prune` / `workspace:remove`
   semantics.
6. Reports the configured instance override, effective resolution, and any workspace
   cleanup that ran.

`instance:agent-ide` is a configuration write with potential side effects. `inherit`
clears the instance override so the effective adapter falls back to its serving
node default. `none` disables agent IDE resolution for the instance and its workspaces
unless a future workspace-level override exists. Cleanup is intentionally
instance-scoped: `node:agent-ide` changes inherited defaults but does not prune
workspaces for those instances.

`instance:agent-ide` does not:

- Create workspaces.
- Create an agent IDE session.
- Create or enable adapter-side app projects. For OpenCode, project resolution
  and sandbox registration are owned by `workspace:new` when the effective
  workspace source driver is `opencode`.
- Grant node access or alter node transport.
- Open a direct node shell. Any workspace-removal side effects use the normal
  Agent-push lane.
- Notify running agent-IDE sessions, restart processes on the node, invalidate
  cached workspace-level overrides, or emit warnings for "downstream consumers
  still using the prior adapter". Current workspaces
  resolve their effective agent IDE per-event through the instance override,
  then its serving-node default, and pick up the new value at the next
  consumer-side resolution event.

## Output

Human output summarizes the configured instance override, effective resolution, and
lists any workspaces removed during an adapter switch.

JSON output returns the dotted instance target, resulting adapter configuration,
and any cleanup results. Cleanup that fails after the instance configuration write is reported as
success with structured warning metadata. See the
[JSON renderer contract](technical/6.2_instance-agent-ide_output-render_json.md) for the exact
payload shape.

## Requirements

- The caller's grant on the instance's serving node must include the `instance:agent`
  permission. Denials surface as `authorization_failed`.
- The caller must not have active `app-prod`; existing grants do not bypass
  the workspace boundary.
- The adapter must be present in the gateway-owned adapter registry. Adapters
  shipped by installed Orbit extensions become valid only after the extension
  has registered them with the gateway.
- Destructive switches require `--force` or interactive confirmation.

## Related Commands

Use these commands alongside `instance:agent-ide` to inspect or prune instance configuration.

- [`project:show`](../4_project-show/project-show.md) — show app details
- [`instance:prune`](../7_instance-prune/instance-prune.md) — explicit instance-level cleanup
- [`node:agent-ide`](../../1_node/10_node-agent-ide/node-agent-ide.md) — set node-level default
- [`doctor --family=instance`](../instance-doctor.md) — verify the agent IDE configuration owned by the app

## Technical Contract

See [`instance:agent-ide` technical contract](technical/1_instance-agent-ide.md).
