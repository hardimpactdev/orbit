# Technical Contract: `orbit workspace:setup` (Gateway Node)

This contract defines behavior when `workspace:setup` is invoked from a
**gateway-node peer**.

[Back to the canonical technical contract.](1_workspace-setup.md)

## Behavior

- **Direct orchestration**: The gateway caller executes the workspace setup
  flow locally without forwarding through the gateway HTTPS API.
- **Registry write**: Directly writes or updates workspace configuration in
  the gateway database.
- **Local path resolution**: Resolves the workspace path on the owning app
  node through gateway-owned SSH inspection, not on the gateway filesystem.
- **Remote apply**: Applies runtime artifacts and setup steps through the
  classified host execution lane.
- **Target eligibility**: The owning node must be an active node;
  the gateway is never a valid app target.

## Authorization

- Requires gateway-level administrative privileges or an authenticated
  gateway-side identity authorized to manage the parent app.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Actions/Workspaces/SetupWorkspaceActionTest.php` | Gateway setup orchestration, lifecycle transition, proxy/process side effects, setup-step execution, and mocked remote shell behavior. |
