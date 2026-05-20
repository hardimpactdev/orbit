# Technical Contract: `orbit workspace:new` (App Role)

**Caller peer:** Node.

[Back to the canonical technical contract.](1_workspace-new.md)

## Validity

- **Denied by the gateway.** Gateway-owned authorization rejects
  `workspace:new` from an app-role peer. Workspace configuration and
  cross-node SSH application are gateway-only responsibilities.

## Behavior

- **Gateway Rejection:** The CLI forwards the request like any other client;
  the gateway authenticates the app-role peer, applies authorization, and
  rejects the command before prompts, SSH, or any other side effects.
- **Reason:** `workspace:new` orchestrates gateway configuration and SSH
  application to the owning node carrying an app role. Nodes are targets of application,
  not originators of workspace creation. This mirrors `app:new` and
  `workspace:setup` rejection semantics for non-permitted callers.

## Failure Mode

- **Error code:** `caller_role_not_allowed`.
- **Human message:** "Command 'workspace:new' cannot be initiated from a node carrying an app role. Run it from a control or gateway node."

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Workspaces/WorkspaceNewOnAppNodeRejectionTest.php` | App-role caller rejection before side effects, with `caller_role_not_allowed` and documented TTY output. |
