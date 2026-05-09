# Gateway Commands

Onboard a control node onto an existing gateway. Spec: [`docs/commands/2_gateway/`](../../../docs/commands/2_gateway/).

For first-gateway bootstrap (no gateway yet), use `node:new --role=gateway` instead — that path also onboards the initiating control node so you should **not** run `gateway:add` afterward on that machine.

## `orbit gateway:add [gateway_ip]`

Register the local control node's connection to a gateway and trust its CA.

```bash
orbit gateway:add [<gateway_ip>] [--json]
```

| Argument | Notes |
|---|---|
| `gateway_ip` | The gateway's WireGuard IP (e.g. `10.6.0.1`). Prompted when omitted. |

Prerequisite: the operator has already enrolled this machine on the gateway with `orbit node:new <name> --role=control` and installed the returned WireGuard config locally so the WireGuard tunnel is up.

What it does: fetches `GET /api/me` from the gateway to confirm identity, writes the gateway row and the local self-row in the nodes table, stores the local gateway endpoint, and trusts the gateway root CA in the local OS trust store. Idempotent — re-running on an already-onboarded host is a no-op.

## `orbit gateway:trust`

Just trust the gateway root CA in the local OS trust store. Use this if the trust step failed during `gateway:add`, or to refresh trust after the OS keychain was reset.

```bash
orbit gateway:trust [--json]
```

Does not touch gateway config or the nodes table — purely a local certificate-trust action.
