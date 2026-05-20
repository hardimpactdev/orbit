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

`dns` supports lifecycle actions (`tool:start`, `tool:stop`, `tool:restart`),
`tool:update`, `tool:logs`, safe doctor fix, and safe doctor adopt.

`tool:install dns` and `tool:remove dns` are not operator-facing commands.
They are reachable only through the gateway bootstrap path described in
[the DNS bootstrap contract](../dns-bootstrap-contract.md).

## Credentials

`dns` does not support `tool:credentials`.

## Orbit Notes

The `dns` tool is the runtime capability behind Orbit-managed DNS
infrastructure. DNS records, zones, provider configuration, and DNS command
behavior remain owned by the DNS command family.

In the current topology, the DNS runtime tool is gateway infrastructure. App and
clients do not own DNS runtime rows unless a future DNS contract expands
node-local DNS support.

The runtime layout — `wg-easy` plus `orbit-dns` sharing wg-easy's network
namespace so dnsmasq binds the wg-easy WG IP — is specified in
[the DNS bootstrap contract](../dns-bootstrap-contract.md).

## Doctor Relationship

`doctor --family=tool` verifies the DNS runtime tool. DNS record drift belongs
to the DNS family. The three runtime drift kinds (`tool.dns_container_missing`,
`tool.dns_port_not_listening`, `tool.dns_config_drift`) are specified in
[the DNS bootstrap contract](../dns-bootstrap-contract.md).
