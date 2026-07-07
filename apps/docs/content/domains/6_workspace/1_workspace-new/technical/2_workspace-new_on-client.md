# Technical Contract: `orbit workspace:new` (Operator Node)

**Caller peer:** Operator identity.

[Back to the canonical technical contract.](1_workspace-new.md)

## Behavior

When run from a client with an operator identity, `workspace:new` acts as a gateway client:

- **Input Resolution:** Gathers all arguments and options. Resolves the
  parent app from `--app`, the `.orbit/config` marker, or the gateway
  path-ownership lookup. Performs local static validation (slug regex,
  length, reserved name) before calling the gateway.
- **Gateway Dispatch:** Forwards the resolved configuration to the gateway
  over HTTPS through WireGuard.
- **Apply:** The gateway performs the gateway workspace row write and
  orchestrates owning-node application through the classified host execution
  lane. The client never opens an SSH connection to the node.
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
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceWriteCommandTest.php` | Client-side workspace:new validation and gateway stream request payload. |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceStreamCommandTest.php` | Workspace stream consumption, terminal JSON frame handling, human progress rendering, and malformed stream failures. |

There is no gateway-side coverage for this client-forwarding path: request construction and stream handling live in `apps/cli`; gateway creation behavior is mapped in the gateway-node and command-level contracts. Documented error.code values and warning payload shape are not exhaustively asserted by the linked CLI tests unless the rows above name them explicitly.
