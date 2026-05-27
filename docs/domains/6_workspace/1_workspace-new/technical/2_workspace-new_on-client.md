# Technical Contract: `orbit workspace:new` (Node)

**Caller peer:** Client.

[Back to the canonical technical contract.](1_workspace-new.md)

## Behavior

When run from a client, `workspace:new` acts as a gateway client:

- **Input Resolution:** Gathers all arguments and options. Resolves the
  parent app from `--app`, the `.orbit/config` marker, or the gateway
  path-ownership lookup. Performs local static validation (slug regex,
  length, reserved name) before calling the gateway.
- **Gateway Dispatch:** Forwards the resolved configuration to the gateway
  over HTTPS through WireGuard.
- **Apply:** The gateway performs the gateway workspace row write and
  orchestrates owning-node application over SSH via `RemoteShell`. The client
  never opens an SSH connection to the node.
- **Progress Streaming:** Human output streams the step tree and apply
  progress from the gateway to the local renderer. JSON output uses the typed
  gateway response envelope and does not render a progress tree.

## Connectivity

- Requires WireGuard connectivity to the gateway.
- Requires trust of the gateway's root CA.

## Authorization

- Requires a valid client identity authorized to manage the parent
  app and its node.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceNewCommandTest.php` | Client input gathering, gateway HTTPS forwarding, gateway-driven SSH apply routing, progress-stream consumption, missing-gateway failure shape, and absence of direct SSH from the client. |
| `apps/gateway/tests/E2E/Ephemeral/WorkspaceNewControlForwardingTest.php` | Real-environment smoke coverage: `workspace:new` invoked from a client forwards to the gateway over WireGuard and produces the expected JSON envelope without writing durable state locally. |
