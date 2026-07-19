# `orbit app:agent-ide [app] [agent_ide]`

[Back to App commands.](../README.md)

Set the default agent IDE adapter for an app.

Stores the app-level agent IDE used by the app and its workspaces. Used when an
app should use a different adapter than the owning node default for messages,
process crash notifications, and workspace source discovery.

## Usage

```bash
orbit app:agent-ide [app] [agent_ide] [--force] [--json]
```

## Examples

```bash
orbit app:agent-ide my-app opencode
orbit app:agent-ide my-app inherit
orbit app:agent-ide my-app none --force
orbit app:agent-ide my-app polyscope --json
```

## Arguments and options

- `app`: app name or hostname. Name match wins; the hostname match is consulted
  only when no name match exists.
- `agent_ide`: agent IDE input value. Core adapter names are `opencode` and
  `polyscope`. Installed Orbit extensions may register additional adapters with
  the gateway. `inherit` and `none` are reserved app-scope tokens, not
  adapters: `inherit` clears the app override so the effective adapter resolves
  through the current chain (`app → node → none`), while `none` disables agent
  IDE resolution for the app and its workspaces. A future workspace-level
  override would sit above app scope.
- `--force`: skip the destructive consent prompt during adapter switches that
  trigger workspace removal.
- `--json`: Output JSON.

## What Happens

Run `app:agent-ide` to set, clear, or disable the agent IDE adapter for an app.

`app:agent-ide` writes the app-default agent-IDE adapter into gateway app
configuration.

An `app-prod` caller cannot run this command because adapter switches may plan
workspace cleanup. A setting stored for an `app-prod` target applies only to
the app main context; production workspace discovery and cleanup never run.

The command:

1. Validates that the target app exists in gateway configuration.
2. Validates that the requested adapter is supported.
3. When switching away from a previous effective adapter, identifies
   workspaces absent from the app under the new adapter. This
   operation requires explicit consent (`--force` or interactive confirmation)
   before any configuration is written.
4. Stores the adapter as the app-level default.
5. Removes stale workspaces through `app:prune` / `workspace:remove`
   semantics.
6. Reports the configured app default, effective resolution, and any workspace
   cleanup that ran.

`app:agent-ide` is a configuration write with potential side effects. `inherit`
clears the app override so the effective adapter falls back to the owning node
default. `none` disables agent IDE resolution for the app and its workspaces
unless a future workspace-level override exists. Cleanup is intentionally
app-scoped: `node:agent-ide` changes the inherited default for apps on a node but
does not prune workspaces for those apps.

`app:agent-ide` does not:

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
  resolve their effective agent IDE per-event through the app default, then the
  owning node default, and pick up the new app default at the next
  consumer-side resolution event.

## Output

Human output summarizes the configured app default, effective resolution, and
lists any workspaces removed during an adapter switch.

JSON output returns the app name, the resulting adapter configuration, and any
cleanup results. Cleanup that fails after the app configuration write is reported as
success with structured warning metadata. See the
[JSON renderer contract](technical/6.2_app-agent-ide_output-render_json.md) for the exact
payload shape.

## Requirements

- The caller's grant on the app's owning node must include the `app:agent`
  permission. Denials surface as `authorization_failed`.
- The caller must not have active `app-prod`; existing grants do not bypass
  the workspace boundary.
- The adapter must be present in the gateway-owned adapter registry. Adapters
  shipped by installed Orbit extensions become valid only after the extension
  has registered them with the gateway.
- Destructive switches require `--force` or interactive confirmation.

## Related Commands

Use these commands alongside `app:agent-ide` to inspect or prune app configurations.

- [`app:show`](../4_app-show/app-show.md) — show app details
- [`app:prune`](../7_app-prune/app-prune.md) — explicit app-level cleanup
- [`node:agent-ide`](../../1_node/10_node-agent-ide/node-agent-ide.md) — set node-level default
- [`doctor --family=app`](../app-doctor.md) — verify the agent IDE configuration owned by the app

## Technical Contract

See [`app:agent-ide` technical contract](technical/1_app-agent-ide.md).
