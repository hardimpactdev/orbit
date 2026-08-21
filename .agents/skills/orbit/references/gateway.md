# Gateway Commands

Onboard a local client onto an existing gateway. Spec:
[`apps/docs/content/domains/2_gateway/`](../../../../apps/docs/content/domains/2_gateway/).

For first-gateway bootstrap (no gateway yet), use
`node:new --template=gateway` instead. That path also onboards the initiating
client identity, so do **not** run `gateway:add` afterward on that same machine.

## `orbit gateway:add [gateway_ip]`

Register the local client's connection to a gateway and trust its CA.

```bash
orbit gateway:add [<gateway_ip>] [--name=<name>] [--json]
```

| Argument | Notes |
|---|---|
| `gateway_ip` | The gateway's WireGuard IP (e.g. `10.6.0.1`). Prompted when omitted. |

`--name` sets the local gateway name. It defaults to `default`.

Prerequisite: the gateway already knows this machine's client identity, usually
created with `orbit node:new <name> --operator`, and the returned WireGuard
config is installed locally so the tunnel is up.

What it does: fetches `GET /api/me` from the gateway to confirm identity,
stores the named gateway endpoint and active selection in
`~/.config/orbit/config.json`, stores gateway trust material under
`~/.config/orbit/gateways/<name>/`, and installs the gateway root CA in the
local OS trust store. It does not create a local Orbit database, gateway row,
or self-row. Re-running it converges the stored endpoint and trust state.

## `orbit gateway:trust`

Install or refresh the gateway root CA in the local OS trust store. Use this if
the trust step failed during `gateway:add`, or to refresh trust after the OS
keychain was reset.

```bash
orbit gateway:trust [--json]
```

Writes caller-local trust metadata after the trust-store action succeeds. It
does not change the configured gateway endpoint or create gateway, client, or
node records.

## `orbit gateway:list`

List local gateway entries configured on this machine.

```bash
orbit gateway:list [--json]
```

## `orbit gateway:use [name]`

Select the active local gateway entry.

```bash
orbit gateway:use [<name>] [--json]
```

## `orbit gateway:status`

Check the active gateway API status.

```bash
orbit gateway:status [--json]
```
