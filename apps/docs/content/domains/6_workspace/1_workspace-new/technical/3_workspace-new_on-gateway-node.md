# Technical Contract: `orbit workspace:new` (Gateway Node)

**Caller peer:** Gateway node.

[Back to the canonical technical contract.](1_workspace-new.md)

## Behavior

When run from a gateway node, `workspace:new` skips the HTTPS API hop and
executes the workspace creation flow locally:

- **Bypasses API:** The CLI calls the underlying PHP action/service classes
  directly.
- **Identity Write:** Writes the gateway workspace row to local SQLite.
- **Apply:** Same as the client path: the gateway orchestrates
  remote work over SSH to the parent app's owning node and runs the
  workspace setup pipeline through `RemoteShell`.
- **Identity:** Uses the gateway's own node identity for authorization.

## Constraints

- Only valid if the local node role is `gateway`.
- The parent app's owning node is the apply target; the gateway is
  never a workspace host.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceStoreControllerTest.php` | Gateway-local workspace creation API, created action payload, path/base metadata, and authorization failure handling. |
