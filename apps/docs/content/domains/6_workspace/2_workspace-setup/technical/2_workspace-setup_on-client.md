# Technical Contract: `orbit workspace:setup` (Operator Node)

This contract defines behavior when `workspace:setup` is invoked from a
**operator identity**.

[Back to the canonical technical contract.](1_workspace-setup.md)

## Behavior

- **Gateway proxy**: The command acts as a client to the Orbit gateway over
  HTTPS through WireGuard when configured and authorized.
- **Identity**: Uses the client's authorized identity to authenticate
  with the gateway.
- **Local context resolution**: Attempts to resolve the parent project and
  workspace identity from local Orbit configuration, current directory, or
  structured Codex Git-worktree metadata for an explicit Codex `--path` before
  forwarding configuration. This local metadata resolution is not an Agent IDE
  adapter.
- **Progress streaming**: Streams the step tree and apply progress from
  the gateway to the local TTY.
- **Local config update**: If successful and the workspace is absent from local
  tracking, updates local workspace tracking.
- **Apply**: The gateway performs the actual application to the owning node
  through Agent push.

## Authorization

- Requires a valid client identity.
- The authenticated WireGuard identity must hold the `workspace:setup`
  permission on the workspace's owning node (a self-grant when run from that
  node). See [Architecture: Self-grants and
  self-serving](../../../../architecture.md#self-grants-and-self-serving).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceWriteCommandTest.php` | Client setup stream request payload, caller cwd forwarding, and local validation. |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceStreamCommandTest.php` | Gateway-authored setup progress, JSON terminal frames, and stream failure handling. |

There is no gateway-side coverage for this client-forwarding path: request construction and stream handling live in `apps/cli`; gateway setup orchestration is mapped in the gateway-node and command-level contracts. Documented error.code values and warning payload shape are not exhaustively asserted by the linked CLI tests unless the rows above name them explicitly.
