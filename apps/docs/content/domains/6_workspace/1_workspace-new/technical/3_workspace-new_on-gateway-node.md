# Technical Contract: `orbit workspace:new` (Gateway Node)

**Caller peer:** Gateway node.

[Back to the canonical technical contract.](1_workspace-new.md)

## Behavior

When run from a gateway node, `workspace:new` uses the same typed gateway HTTPS
API as every other public CLI caller:

- **API boundary:** The CLI submits the workspace request through the typed
  HTTPS endpoint; caller location does not bypass the public contract.
- **Identity write:** The gateway writes the canonical instance-owned
  workspace row after authorization succeeds.
- **Apply:** The gateway dispatches runtime work to the instance's owning
  node through Agent push and runs the workspace setup pipeline there.
- **Identity:** The request carries the gateway node's WireGuard identity for
  gateway implicit-authority policy and audit evaluation.

## Constraints

- Caller location does not create a second command path; this file describes
  the gateway-located invocation and its named gateway implicit-authority
  authorization class.
- The parent instance's owning node is the apply target; the gateway is
  never a workspace host.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceStoreControllerTest.php` | Gateway-local workspace creation API, created action payload, path/base metadata, and authorization failure handling. |
