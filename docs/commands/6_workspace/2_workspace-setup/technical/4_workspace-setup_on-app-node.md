# Technical Contract: `orbit workspace:setup` (App Node)

This contract defines behavior when `workspace:setup` is invoked from an
**app-node peer**.

[Back to the canonical technical contract.](1_workspace-setup.md)

## Behavior

The gateway authorizes `workspace:setup` from an app-node peer as a documented
local-workflow exception. App nodes do not own durable Orbit state, but the
Orbit CLI on an app node may run `workspace:setup` so developers and agents
working *inside* a workspace can re-converge that workspace without switching
to a control or gateway node.

- **Thin gateway client**: The CLI on the app node behaves identically to a
  control-peer CLI. It resolves local context, forwards the resolved
  configuration to the gateway over HTTPS through WireGuard, and waits for the
  gateway to apply artifacts on the same app node via `RemoteShell`. The CLI
  never applies artifacts directly even though the gateway's SSH target is the
  caller's own host.
- **Local context resolution**: Resolves `[name]`, `--app`, and `--path` from
  the working directory and `.orbit/` markers when the caller is inside a
  managed workspace tree.
- **Forwarding**: The gateway is the sole writer; the CLI never bypasses the
  gateway to apply workspace artifacts directly.
- **Progress streaming**: Streams the gateway's step tree and apply progress
  back to the caller's TTY.

The gateway authorizes app-node peers for this command as the current
app-node local workflow write exception.

## Allowed Paths

- Forwarding `workspace:setup` configuration to the gateway over HTTPS.
- Streaming progress from the gateway back to the local TTY.
- Updating local workspace markers when the gateway run succeeds.

The CLI may not write workspace configuration, mutate the gateway database, or
apply artifacts to any node.

## Authorization

- Requires the caller's WireGuard peer identity to be authorized by the
  gateway to manage the parent app's workspaces.
- Authorization is enforced by the gateway, not by the CLI.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Workspaces/WorkspaceSetupOnAppNodeTest.php` | App-node caller forwarding to the gateway, local-context resolution from the working directory, denial of direct artifact writes from the CLI, and unauthorized-app rejection on the gateway side. |
