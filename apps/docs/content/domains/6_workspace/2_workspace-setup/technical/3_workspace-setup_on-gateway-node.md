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
- **Remote apply**: Connects to the node over SSH to apply runtime
  artifacts and run setup steps via `RemoteShell`.
- **Target eligibility**: The owning node must be an active node;
  the gateway is never a valid app target.

## Authorization

- Requires gateway-level administrative privileges or an authenticated
  gateway-side identity authorized to manage the parent app.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupOnGatewayNodeTest.php` | Gateway local orchestration without HTTPS forwarding, owning-node target eligibility, gateway-owned SSH path resolution, and `RemoteShell`-based artifact application. |
