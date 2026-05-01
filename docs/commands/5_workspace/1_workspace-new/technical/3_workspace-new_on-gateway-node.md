# Technical Contract: `orbit workspace:new` (Gateway Node)

**Caller Role:** `gateway`.

[Back to the canonical technical contract.](1_workspace-new.md)

## Behavior

When run from a gateway node, `workspace:new` skips the HTTPS API hop and
executes the workspace creation flow locally:

- **Bypasses API:** The CLI calls the underlying PHP action/service classes
  directly.
- **Identity Write:** Writes the gateway workspace row to local SQLite.
- **Enactment:** Same as the control-node path: the gateway orchestrates
  remote work over SSH to the parent app's owning app node and runs the
  workspace setup pipeline through `RemoteShell`.
- **Identity:** Uses the gateway's own node identity for authorization.

## Constraints

- Only valid if the local node role is `gateway`.
- The parent app's owning app node is the enactment target; the gateway is
  never a workspace host.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Workspaces/WorkspaceNewOnGatewayNodeContractTest.php` | Gateway-local execution: direct invocation of the underlying actions/services, SQLite intent write, SSH enactment to the parent app node via `RemoteShell`, and identical observable result to the forwarded path. |
| `tests/E2E/Ephemeral/WorkspaceNewGatewayLocalTest.php` | Real-environment smoke coverage: `workspace:new` invoked on a gateway node bypasses the HTTPS hop and produces equivalent gateway intent and node artifacts as the control-caller path. |
