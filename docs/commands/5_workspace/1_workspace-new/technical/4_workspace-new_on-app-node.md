# Technical Contract: `orbit workspace:new` (App Node)

**Caller Role:** `app`.

[Back to the canonical technical contract.](1_workspace-new.md)

## Validity

- **Rejected.** App nodes are not permitted to initiate workspace creation.
  Workspace intent and cross-node SSH enactment are gateway-only
  responsibilities.

## Behavior

- **Pre-flight Rejection:** The CLI rejects the command before prompts,
  forwarding, SSH, or any side effects when the local node role is `app`.
- **Reason:** `workspace:new` orchestrates gateway intent and SSH enactment
  to the owning app node. App nodes are targets of enactment, not
  originators of workspace creation. This mirrors `app:new` and
  `workspace:setup` rejection semantics.

## Failure Mode

- **Error code:** `caller_role_not_allowed`.
- **Human message:** "Command 'workspace:new' cannot be initiated from an app node. Run it from a control or gateway node."

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Workspaces/WorkspaceNewOnAppNodeRejectionTest.php` | App-node caller rejection: `workspace:new` exits before prompts, side effects, or registry reads when `general.local_node_role=app`, with `error.code=caller_role_not_allowed` in JSON output and the documented human message in TTY output. |
