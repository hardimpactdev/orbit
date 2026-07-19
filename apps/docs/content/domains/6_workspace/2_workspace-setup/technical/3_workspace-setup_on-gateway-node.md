# Technical Contract: `orbit workspace:setup` (Gateway Node)

This contract defines behavior when `workspace:setup` is invoked from a
**gateway-node peer**.

[Back to the canonical technical contract.](1_workspace-setup.md)

## Behavior

- **Typed API boundary**: The gateway caller submits the setup request through
  the same typed gateway HTTPS API as every other public CLI caller.
- **Registry write**: The gateway writes the canonical app-instance-owned
  workspace configuration after authorization succeeds.
- **Path resolution**: Resolves the workspace path on the app instance's owning
  node through Agent push, not on the gateway filesystem.
- **Remote apply**: Applies runtime artifacts and setup steps through Agent
  push to the app instance's owning node.
- **Target eligibility**: The owning node must be active and carry `app-dev`;
  the gateway and `app-prod` nodes are never valid workspace targets.

## Authorization

- Uses the gateway caller's WireGuard identity and requires the same gateway
  policy grant as the client invocation. Caller location changes identity
  context, not transport or authorization semantics.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Actions/Workspaces/SetupWorkspaceActionTest.php` | Gateway setup orchestration, lifecycle transition, proxy/process side effects, setup-step execution, and Agent-push behavior. |
