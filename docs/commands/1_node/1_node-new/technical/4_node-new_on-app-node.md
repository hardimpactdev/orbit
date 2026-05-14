# Technical Contract: `node:new` Rejected For App Callers

[Back to `node:new` technical contract.](1_node-new.md)

This page describes how the gateway rejects `node:new` for callers whose
authenticated node record has role `app`.

**Effects:** `none`. The gateway rejects the request before side effects.

## App-Node Rules

On an app node, the CLI remains a thin gateway client: it gathers command
input, sends the request to the gateway over the VPN, and renders the gateway
rejection. The CLI does not inspect local app-node role state or reject the
command from a local node record.

**Prerequisites:**
- The gateway authenticates the caller and resolves its role to `app`.
- No requested role, command argument, option, or WireGuard identity prerequisite
  can make `node:new` valid for an app-role caller.

## Allowed Paths

None. The gateway authorizes no requested role for app-role callers.

| Requested role | Behavior |
| --- | --- |
| `gateway` | Fail before prompts or side effects. |
| `app` | Fail before prompts or side effects. |
| `control` | Fail before prompts or side effects. |

## Error Contract

When the gateway authenticates an app-role caller, it returns this exact
human error:

```text
This command may only be run from a control or gateway node.
```

JSON mode returns a structured error with the same message.

## Gateway-side rules for app callers

- Do not authorize any node creation, enrollment, provisioning, or
  convergence path.
- Do not mint WireGuard identity material on the app-role caller's behalf.

## Failure Semantics

- Reject before gateway-owned side effects for every requested role.
- The CLI exits with the standard command failure status.
- The failure is a caller-role authorization decision at the gateway.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeNewOnAppNodeContractTest.php` | Primary owner for app-caller rejection: app-role callers with `--role=gateway`, `--role=app`, `--role=control`, and no `--role` are rejected by the gateway before gateway-owned writes, SSH, or WireGuard minting. Renderer tests own the human and JSON formatting of that error. |
| `tests/E2E/Ephemeral/NodeNewAppNodeRejectionTest.php` | Real-node smoke coverage proving the gateway rejects app-role callers before any node-state mutation. |
