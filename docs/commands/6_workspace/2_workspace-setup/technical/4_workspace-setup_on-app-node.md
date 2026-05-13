# Technical Contract: `orbit workspace:setup` (App Node)

This contract defines behavior when `workspace:setup` is invoked from an
**app node**.

[Back to the canonical technical contract.](1_workspace-setup.md)

## Behavior

`workspace:setup` is **valid** from app-node callers as a documented
local-workflow exception. App nodes do not own durable Orbit state, but the
Orbit CLI on an app node may run `workspace:setup` so developers and agents
working *inside* a workspace can re-converge that workspace without
switching to a control or gateway node.

- **Stateless gateway client**: The CLI on the app node does not write
  workspace intent locally. It resolves local context, forwards the resolved
  intent to the gateway over HTTPS through WireGuard, and waits for the
  gateway to enact artifacts on the same app node via `RemoteShell`.
- **Local context resolution**: Resolves `[name]`, `--app`, and
  `--path` from the app-node working directory and `.orbit/` markers when
  the caller is inside a managed workspace tree.
- **Forwarding**: The gateway is the sole writer; the app-node CLI never
  bypasses the gateway to enact workspace artifacts directly.
- **Progress streaming**: Streams the gateway's step tree and enactment
  progress back to the app-node TTY.

This exception is explicitly named by
[ARCHITECTURE.md#app-node](../../../../ARCHITECTURE.md#app-node) as the current
app-node local workflow write exception.

## Allowed Paths

- Forwarding `workspace:setup` intent to the gateway over HTTPS.
- Streaming progress from the gateway back to the local TTY.
- Updating local app-node workspace markers if the gateway run succeeds.

The app-node CLI may not write workspace intent, mutate the gateway database,
or enact artifacts on other nodes.

## Authorization

- Requires an app-node identity authorized by the gateway to manage the
  parent app's workspaces.
- Authorization is enforced by the gateway, not the app-node CLI.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Workspaces/WorkspaceSetupOnAppNodeTest.php` | App-node caller forwarding to the gateway, local-context resolution from the working directory, denial of direct artifact writes from the app-node CLI, and unauthorized-app rejection on the gateway side. |
