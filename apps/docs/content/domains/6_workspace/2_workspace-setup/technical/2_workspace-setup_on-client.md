# Technical Contract: `orbit workspace:setup` (Operator Node)

This contract defines behavior when `workspace:setup` is invoked from a
**operator identity**.

[Back to the canonical technical contract.](1_workspace-setup.md)

## Behavior

- **Gateway proxy**: The command acts as a client to the Orbit gateway over
  HTTPS through WireGuard when configured and authorized.
- **Identity**: Uses the client's authorized identity to authenticate
  with the gateway.
- **Local context resolution**: Attempts to resolve the parent app and
  workspace identity from local Orbit configuration or current directory
  before forwarding configuration.
- **Progress streaming**: Streams the step tree and apply progress from
  the gateway to the local TTY.
- **Local config update**: If successful and the workspace is absent from local
  tracking, updates local workspace tracking.
- **Apply**: The gateway performs the actual SSH application to the owning
  node.

## Authorization

- Requires a valid client identity.
- The authenticated WireGuard identity must hold the `workspace:setup`
  permission on the workspace's owning node (a self-grant when run from that
  node). See [Architecture: Self-grants and
  self-serving](../../../../architecture.md#self-grants-and-self-serving).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupOnOperatorNodeTest.php` | Operator-caller forwarding to the gateway, identity propagation, local-context resolution before forwarding, and progress streaming back to the local TTY. |
