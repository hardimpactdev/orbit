# Tool Catalog: `dns`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the DNS tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `dns` |
| Label | DNS |
| Backend | Docker service |
| Support model | Required infrastructure tool, adopted and kept converged |
| Category | `infrastructure` |

## Capabilities

`dns` supports `tool:update`, safe doctor fix, and safe doctor adopt for the
gateway DNS substrate capability. Compatibility lifecycle and log commands may
route to the related DNS runtime process while this tool row remains the
bootstrap/adoption record; lifecycle ownership belongs to the runtime process.

`tool:install dns` and `tool:remove dns` are not operator-facing commands.
They are reachable only through the gateway bootstrap path described in
[the DNS bootstrap contract](../dns-bootstrap-contract.md).

## Credentials

`dns` does not support `tool:credentials`.

## Orbit Notes

The `dns` tool is the runtime capability behind the gateway's VPN-facing DNS
substrate (dnsmasq inside wg-easy's network namespace). The substrate as a
whole is part of the `vpn` role baseline. This tool row tracks the substrate's
container, port, and config so `doctor --family=tool` can verify drift in
those specifically. DNS mapping records — which TLD points at which WireGuard
IP — are owned by the node family. The `dns:*` command family owns only
caller-local resolver overrides on operator machines. See
[Architecture: DNS responsibilities](../../../architecture.md#dns-responsibilities)
for the full split.

In the current topology, the DNS runtime tool is gateway infrastructure. App
nodes and clients do not own DNS runtime rows.

The runtime layout — `wg-easy` plus `orbit-dns` sharing wg-easy's network
namespace so dnsmasq binds the wg-easy WG IP — is specified in
[the DNS bootstrap contract](../dns-bootstrap-contract.md).

## Doctor Relationship

`doctor --family=tool` verifies the DNS runtime tool's container, port, and
config-content drift. Drift in *which DNS mappings should exist* — a new
`app-dev` or `agent` role appeared without a matching mapping line —
is node-family drift, not tool drift, and is verified by
`doctor --family=node`. The three drift kinds covered by `doctor --family=tool`
(`tool.dns_container_missing`, `tool.dns_port_not_listening`,
`tool.dns_config_drift`) are specified in
[the DNS bootstrap contract](../dns-bootstrap-contract.md).
