# Technical Contract: `orbit workspace:setup` (Control Node)

This contract defines behavior when `workspace:setup` is invoked from a
**control node**.

[Back to the canonical technical contract.](1_workspace-setup.md)

## Behavior

- **Gateway proxy**: The command acts as a client to the Orbit gateway over
  HTTPS through WireGuard when configured and authorized.
- **Identity**: Uses the control node's authorized identity to authenticate
  with the gateway.
- **Local context resolution**: Attempts to resolve the parent app and
  workspace identity from local Orbit configuration or current directory
  before forwarding intent.
- **Progress streaming**: Streams the step tree and enactment progress from
  the gateway to the local TTY.
- **Local config update**: If successful and not previously tracked locally,
  updates local workspace tracking.
- **Enactment**: The gateway performs the actual SSH enactment to the owning
  app node.

## Authorization

- Requires a valid control-node identity.
- Identity must have `manage` or `write` permission for the parent app.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Workspaces/WorkspaceSetupOnControlNodeTest.php` | Control-caller forwarding to the gateway, identity propagation, local-context resolution before forwarding, and progress streaming back to the local TTY. |
