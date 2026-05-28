# Technical Contract: `orbit workspace:setup` (Node)

This contract defines behavior when `workspace:setup` is invoked from a
**client peer**.

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
- **Local config update**: If successful and not previously tracked locally,
  updates local workspace tracking.
- **Apply**: The gateway performs the actual SSH application to the owning
  node.

## Authorization

- Requires a valid client identity.
- Identity must have `manage` or `write` permission for the parent app.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupOnControlNodeTest.php` | Operator-caller forwarding to the gateway, identity propagation, local-context resolution before forwarding, and progress streaming back to the local TTY. |
