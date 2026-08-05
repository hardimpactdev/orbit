# Tool Catalog: `dns`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the DNS tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `dns` |
| Label | DNS |
| Backend | Docker service |
| Support model | Required gateway-local infrastructure tool, restored from canonical intent |
| Category | `infrastructure` |
| Supported operating systems | Linux |
| Required container provider | Docker-compatible |
| Isolation | Docker network namespace |
| Locality | Gateway-local |

## Capabilities

`dns` supports `tool:update`, safe doctor restore, `tool:restart`, and
`tool:logs` for its one direct gateway-local `orbit-dns` runtime. It does not
support start, stop, reload, or adoption. Restart is public because dnsmasq
address-rule changes require it; availability remains bootstrap/restore-owned.
No related-process adapter is involved.

`tool:install dns` and `tool:remove dns` are not operator-facing commands.
They are reachable only through the gateway bootstrap path described in
[the DNS bootstrap contract](../dns-bootstrap-contract.md).

## Credentials

`dns` does not support `tool:credentials`.

## Orbit Notes

The `dns` tool is the runtime capability behind the gateway's VPN-facing DNS
substrate. The `vpn` role requires it, but Orbit has no `dns` role. This tool
row owns base `dnsmasq.conf`, the container/service, port-53 listener, VPN
forwarding, and client-DNS settings. The node family owns
`dnsmasq.d/10-node-records.conf`; the proxy family owns
`dnsmasq.d/20-proxy-records.conf`. The shared materializer and reload path is
ownership-neutral. The `dns:*` command family owns only
caller-local resolver overrides on operator machines. See
[Architecture: DNS responsibilities](../../../architecture.md#dns-responsibilities)
for the full split.

The DNS runtime is gateway-local infrastructure. App nodes and clients do not
own DNS runtime rows, and the command must execute locally on the gateway rather
than dispatching to a workload node.

The runtime layout — `wg-easy` plus `orbit-dns` sharing wg-easy's network
namespace so dnsmasq binds the wg-easy WG IP — is specified in
[the DNS bootstrap contract](../dns-bootstrap-contract.md).

## Doctor Relationship

`doctor --family=tool` verifies base configuration, container, listener,
client-DNS, and forwarding drift. Record-content drift is reported by the
family that owns the appion. All five DNS tool codes are restore-only:
`tool.dns_container_missing`, `tool.dns_port_not_listening`,
`tool.dns_base_config_mismatch`, `tool.dns_client_dns_drift`, and
`tool.dns_forwarding_missing`. Their exact recovery behavior is specified in
[the DNS bootstrap contract](../dns-bootstrap-contract.md). Emergency edits
must be translated into node or proxy intent before restoring that family.
