# Technical Contract: `node:grant` Rejected For App Callers

[Back to `node:grant` technical contract.](1_node-grant.md)

This page describes how the gateway rejects `node:grant` for callers whose
authenticated node record has role `app`.

**Effects:** `none`. The gateway rejects the request before side effects.

## App-Node Rules

On an app node, the CLI remains a thin gateway client: it gathers command
input, sends the request to the gateway over the VPN, and renders the gateway
rejection. The CLI does not inspect local app-node role state or reject the
command from a local node record.

**Prerequisites:**
- The gateway authenticates the caller and resolves its role to `app`.
- No command argument, option, or WireGuard identity prerequisite can make
  `node:grant` valid for an app-role caller.

## Allowed Paths

None. The gateway authorizes nothing for app-role callers.

## Error Contract

When the gateway authenticates an app-role caller, it returns this exact
human error:

```text
This command may only be run from a control or gateway node.
```

JSON mode returns a structured error with the same message.

## Gateway-side rules for app callers

- Do not write durable gateway-owned node state.
- Do not read or mutate node access grants on behalf of the caller.

## Failure Semantics

- Reject before gateway-owned side effects for every app-role caller.
- The CLI exits with the standard command failure status.
- The failure is a caller-role authorization decision at the gateway.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeGrantOnAppNodeContractTest.php` | Primary owner for app-caller rejection: app-role callers are rejected by the gateway before gateway-owned writes. Renderer tests own the human and JSON formatting of that error. |
