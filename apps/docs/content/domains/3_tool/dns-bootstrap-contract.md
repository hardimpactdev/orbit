# DNS Bootstrap Contract

[Back to tool catalog.](catalog/README.md)
[Back to tool family.](README.md)

This contract spells out how Orbit provisions private fleet DNS over the VPN.
The current runtime shape is `wg-easy` + `orbit-dns` + a tool-owned base
configuration and two family-owned record projections. It is
referenced from
[`catalog/dns.md`](catalog/dns.md) and from the gateway bootstrap path
(`orbit:internal:bootstrap-gateway-local`).

DNS *commands* — `dns:resolve-tld`, `dns:list` — stay caller-local and are
covered by `docs/domains/16_dns/**`. The **node family** owns
`dnsmasq.d/10-node-records.conf`; the **proxy family** owns
`dnsmasq.d/20-proxy-records.conf`; and the **tool family** owns base
`dnsmasq.conf` plus the DNS runtime, listener, VPN forwarding, and client-DNS
settings. Bootstrap wires the three artifacts into one gateway-local runtime.
The shared materializer and reload path is ownership-neutral. The `vpn` role
requires the DNS tool capability; there is no `dns` role or DNS state family.

## Ownership

This table maps each responsibility for development DNS over the VPN to the
service or command that owns it.

| Concern                                         | Owner                                                                 |
| ----------------------------------------------- | --------------------------------------------------------------------- |
| Installing `wg-easy` on the gateway             | `BootstrapGatewayLocalCommand` → `WgEasyServiceInstaller`             |
| Installing `orbit-dns` on the gateway           | `BootstrapGatewayLocalCommand` → `OrbitDnsServiceInstaller`           |
| Generating base `dnsmasq.conf`                  | Tool family through `DnsmasqBaseConfigBuilder`                        |
| Generating `dnsmasq.d/10-node-records.conf`     | Node family through `NodeDnsmasqRecordsBuilder`                       |
| Generating `dnsmasq.d/20-proxy-records.conf`    | Proxy family through `ProxyDnsmasqRecordsBuilder`                     |
| Publishing artifacts and restarting DNS        | Ownership-neutral `DnsmasqReconciler` materializer/reload path        |
| Probing node record drift                       | `NodeDnsProjectionProbe` under `doctor --family=node`                 |
| Probing proxy record drift                      | `ProxyDnsProjectionProbe` under `doctor --family=proxy`               |
| Probing base/runtime drift                      | `DnsRuntimeProbe` under `doctor --family=tool`                        |

## Bootstrap Step Order

The gateway bootstrap runs in this order:

1. WireGuard kernel + interface install.
2. Root CA materialized.
3. Gateway `orbit-gateway` and `orbit-scheduler` Swarm services created and started.
4. Router-owned `orbit-caddy` container created, started, and configured to
   route HTTPS gateway traffic to `orbit-gateway` over `orbit-network` when
   router and gateway are colocated.
5. **`wg-easy` container started.**
6. **`orbit-dns` container started inside wg-easy's network namespace, with
   all three DNS artifacts materialized before startup.**

`wg-easy` must come up before `orbit-dns`: `orbit-dns` uses
`network_mode: container:wg-easy`, which pins the netns of the wg-easy
container. If wg-easy is not running, `docker compose up -d` for orbit-dns
fails. `OrbitDnsServiceInstaller` enforces this precondition.

## `wg-easy` Provisioning Shape

Compose path: `~/.config/orbit/wg-easy/docker-compose.yaml`.

Required envs / settings:

- `INIT_ENABLED=true` — enables the unattended setup flow in wg-easy v15.
- `INIT_USERNAME=orbit` and `INIT_PASSWORD=<generated>` — bootstrap the admin
  account. The generated password is persisted in `ORBIT_CONFIG_ROOT/.env` (default `~/.config/orbit/.env`) as
  `WG_EASY_PASSWORD=...` so future runs are idempotent and so
  `tool:credentials wg-easy` can later expose it.
- `INIT_HOST=<public host>` — the gateway's public IPv4 or DNS name.
- `INIT_PORT=51820`.
- `INIT_DNS=10.6.0.1` — the wg-easy WG IP, where `orbit-dns` listens via the
  shared netns.
- `INIT_ALLOWED_IPS=10.6.0.0/24`.
- `INSECURE=true` with the admin HTTP port bound to `127.0.0.1`.
- Ports: `51820/udp`, `51821/tcp`.
- Caps: `NET_ADMIN`, `SYS_MODULE`.
- Volumes: `~/.wg-easy:/etc/wireguard`, `/lib/modules:/lib/modules:ro`.

Reinvocation with unchanged inputs is a no-op (idempotent).

## `orbit-dns` Provisioning Shape

Compose path: `~/.config/orbit/docker-compose.yaml` (the standard Orbit
tool compose file), service `orbit-dns`.

Service shape:

- `image: 4km3/dnsmasq:latest`
- `network_mode: container:wg-easy` — shares wg-easy's netns so dnsmasq
  binds on `10.6.0.1:53` inside the VPN. Avoids the `-p 53:53` open-resolver
  hazard.
- read-only mounts for `<config-root>/dnsmasq.conf:/etc/dnsmasq.conf` and
  `<config-root>/dnsmasq.d:/etc/dnsmasq.d`
- `cap_add: [NET_ADMIN]`
- `restart: unless-stopped`

The base configuration and both record projections are rendered before
`docker compose up -d`, so the container starts with a complete valid config.

## Swarm VPN/DNS Target Shape

The Swarm migration keeps VPN and DNS as separate Swarm services. Do not merge
wg-easy and dnsmasq into one image/container: Docker Swarm must be able to
start, stop, restart, replace, and observe the VPN and DNS containers
independently.

Target service shape:

- `wg-easy` and `orbit-dns` are separate Swarm services with one replica each.
- Both services are constrained to the same gateway edge node. In v1 that node
  carries the gateway-coupled `vpn` role and required DNS tool capability.
- Both services attach to the private `orbit-network` Swarm overlay network so
  the VPN service can reach the DNS service by service name.
- `wg-easy` retains `/dev/net/tun`, `NET_ADMIN`, the required WireGuard state
  mount, and host-mode publication of the public WireGuard UDP endpoint.
- `orbit-dns` mounts base `dnsmasq.conf` and the `dnsmasq.d` projection
  directory, listens on port 53 inside the private service network, and
  publishes no public host port.
- Peer configs continue to advertise the VPN-side DNS address, currently
  `10.6.0.1`.
- The VPN service owns forwarding from WireGuard peer DNS traffic to the DNS
  service over `orbit-network`. The forwarding rule must cover UDP and TCP 53,
  avoid public `:53` exposure, and preserve return traffic.
- The VPN service carries a healthcheck that idempotently reapplies that
  forwarding rule inside the VPN task namespace. This keeps DNS available after
  a gateway reboot or Swarm task replacement without waiting for an operator to
  run `doctor --restore`.

The DNS service may be restarted or updated independently when any managed DNS
configuration artifact changes. DNS reconciliation must not restart WireGuard
just because DNS mappings for the node family changed.

## Configuration Artifact Shapes

### Tool-owned base `dnsmasq.conf`

`DnsmasqBaseConfigBuilder::build()` emits deterministic base configuration:

```text
# orbit-managed=dnsmasq-base
no-resolv
server=1.1.1.1
server=8.8.8.8
conf-file=/etc/dnsmasq.d/10-node-records.conf
conf-file=/etc/dnsmasq.d/20-proxy-records.conf
log-queries
log-facility=-
```

The explicit includes admit only Orbit's two record projections; arbitrary
operator drop-ins are not part of the managed runtime contract.

### Node-owned `10-node-records.conf`

`NodeDnsmasqRecordsBuilder` emits the marker
`# orbit-managed=node-dns-records` followed by deterministic directives:

- every active node with a valid non-reserved TLD and WireGuard address receives
  `address=/orbit.{tld}/{wireguard_address}`;
- only a node with an active `app-dev` or `agent` role also receives
  `address=/{tld}/{wireguard_address}` and `local=/{tld}/`; and
- inactive, incomplete, or reserved-TLD node identities are omitted.

Orbit reserves the node TLD `orbit` for the proxy-owned `.orbit` namespace.
Node create and update reject that value before materialization, preventing a
concrete `orbit.orbit` directive from competing with the generic proxy suffix.

### Proxy-owned `20-proxy-records.conf`

`ProxyDnsmasqRecordsBuilder` emits the marker
`# orbit-managed=proxy-dns-records`, followed by exact backend records and then
router/private `.orbit` directives. Router-owned service routes ending in
`.orbit` contribute `address=/orbit/{router_wireguard_address}` and
`local=/orbit/`. The canonical `s3.orbit` route currently contributes one
exact `address=/{node}.s3.orbit/{node_wireguard_address}` record per matching
active S3 backend. Exact backend records precede the generic `.orbit` directive
so backend traffic reaches the workload node rather than looping through the
router.

## Reconciliation

`DnsmasqReconciler` exposes ownership-specific entry points:
`reconcileBase()`, `reconcileNodeRecords()`, and `reconcileProxyRecords()`.
Node identity create, update, remove, and activation call `reconcileRecords()`
to request the node and proxy projections together without base configuration.
Role add/remove that changes only wildcard eligibility calls
`reconcileNodeRecords()`. `stageAllForInstall()` and
`migrateLegacyLayout()` are reserved for installation and explicit layout
migration. The ownership-specific reconciliation entry points and
`migrateLegacyLayout()`:

1. Acquires one shared projection lock before reading canonical gateway intent.
2. Builds only the requested artifacts while holding that lock, so an older
   snapshot cannot overwrite a newer projection.
3. Atomically replaces each changed
   requested artifact. This is per-file atomic replacement under one lock, not
   an all-files transactional publish.
4. Restarts dnsmasq once by forcing the `orbit_orbit-dns` Swarm service update when
   the Swarm stack is present, or by restarting the standalone `orbit-dns`
   container. dnsmasq reloads hosts on `SIGHUP`, but address rules require a
   restart to take effect reliably.
5. Rolls the published bytes back if activation fails, so the next convergence
   attempt cannot mistake unapplied bytes for an activated generation. It skips
   the restart only when every requested artifact was already up to date.

The two pre-deployment staging entry points use the same lock and atomic
materializer but deliberately do not restart the old runtime. After deploying
the new runtime definition, the installer activates changed staged
configuration. Legacy migration instead lets `migrateLegacyLayout()` publish
the final three artifacts and perform that activation.

The lock, per-file atomic materializer, and single restart are shared
infrastructure; they do not own any of the three artifacts.

Existing monolithic-layout deployments migrate in a guarded order. First,
stage header-only, record-free placeholders in `dnsmasq.d` while leaving the
monolithic `dnsmasq.conf` active. Deploy the runtime definition with the
read-only directory mount, then inspect the running container or Swarm task and
prove that the mount source is the configured `dnsmasq.d` directory, its
destination is `/etc/dnsmasq.d`, and it is read-only. Only then may
`migrateLegacyLayout()` publish both semantic record projections followed by
the base file with explicit includes and perform one restart. Persisted Compose
or stack YAML is not proof that the active runtime consumes the mount. Scoped
node, proxy, and tool restores leave legacy-layout drift unresolved; they do
not perform this cross-artifact migration.

Triggers in the gateway application:

- `node:new` for every active node with `tld` and `wireguard_address` set,
  `node:update` when either field changes, and `node:remove` for any node. These
  lifecycle paths use `reconcileRecords()` because backend DNS may change with
  node identity; they never rewrite base `dnsmasq.conf`.
- `node role:add` and `node role:remove` when `app-dev` or `agent` wildcard
  eligibility changes. These paths use `reconcileNodeRecords()`, so they do not
  touch proxy records or base configuration and the concrete node record
  remains while stale wildcard and local-zone directives are removed.
- S3 role activation after the SeaweedFS runtime and tool row are ready. This
  syncs the canonical `s3.orbit` route and reconciles its proxy-owned exact backend DNS
  record immediately for an active node. Client-owned `node:new` repeats that
  sync after its Provisioning-to-Active terminal transition.
- S3 role removal, which updates the remaining backend pool or removes the
  canonical service route when no active S3 backend remains.
- `S3RouteRegistrar::syncServiceRoute()` during S3 publication or proxy-doctor
  restoration.
- WebSocket, metrics, and analytics router-route activation or removal, which
  reconciles the proxy projection only.

The reconciler is idempotent: running it twice in a row is a no-op for the
second call.

## Doctor Contract

Doctor reports DNS drift in the family that owns the mismatched artifact or
runtime fact:

| Drift kind | Owner and detection | Restore | Adopt |
| --- | --- | --- | --- |
| `node.dns_mapping_mismatch` | Node: a source-node concrete/wildcard directive is wrong, or the gateway anchor finds an orphan directive. | Re-render only the node projection and use the shared restart path. | No |
| `proxy.dns_mapping_mismatch` | Proxy: `20-proxy-records.conf` differs from router/private `.orbit` and exact-backend intent. | Re-render only the proxy projection and use the shared restart path. | No |
| `tool.dns_base_config_mismatch` | Tool: base `dnsmasq.conf` differs from `DnsmasqBaseConfigBuilder` output, or the active projection-directory bind source, destination, or read-only mode is wrong. | Rewrite only non-legacy base config, redeploy a wrong mount, and restart or update DNS. Legacy conversion remains an explicit installer migration. | No |
| `tool.dns_container_missing` | Tool: neither the standalone `orbit-dns` container nor Swarm task is present. | Stage base config plus record-free owner placeholders when absent, then rerun the persisted stack/compose installer; Swarm restore also reconverges forwarding. | No |
| `tool.dns_port_not_listening` | Tool: no port-53 listener is available inside the DNS runtime. | Force service update or restart the container. | No |
| `tool.dns_client_dns_drift` | Tool: wg-easy default or enabled-client DNS differs from the active `vpn.dns_ip`. | Rewrite client DNS to the active endpoint. | No |
| `tool.dns_forwarding_missing` | Tool: the Swarm VPN task lacks required UDP/TCP 53 DNAT and MASQUERADE rules. | Reapply forwarding in the VPN task namespace. | No |

`tool.dns_client_dns_drift` is only about WireGuard client DNS configuration stored
in wg-easy. It does not alter dnsmasq upstream resolvers; `server=1.1.1.1` and
`server=8.8.8.8` remain valid upstream forwarding entries inside
`dnsmasq.conf`.

DNS drift is not adoptable. The tool family does not own DNS records; the node
and proxy families do (see
[Architecture: DNS responsibilities](../../architecture.md#dns-responsibilities)).
Emergency DNS edits must be translated into canonical owner intent, then the
owning family's restore path re-renders only its artifact.

Every completed `tool.dns_*` restore is re-probed before Doctor marks it
successful. An accepted asynchronous deploy is not completion: any remaining
container, listener, base/mount, forwarding, or client-DNS issue keeps the
action failed and the drift visible.

The catalog declares `tool:restart dns` and `tool:logs dns` against the single
direct gateway-local `orbit-dns` runtime. Public start, stop, and reload remain
unsupported: bootstrap/restore owns availability, and address-rule changes
require restart rather than reload.

## Why install/remove are not operator commands

`tool:install dns` and `tool:remove dns` are reachable only through this
bootstrap path. Operator-facing install/remove would let a user remove the
DNS substrate from a running gateway and break every WG client's resolver
without a doctor signal first. The catalog reflects that constraint; this
contract is the explicit exception that authorizes bootstrap to call the
underlying installer.

`tool:update dns` re-runs the installer, re-emits the runtime definition,
materializes all three canonical artifacts for bootstrap compatibility, runs
`docker compose up -d`, and restarts the DNS runtime when staged bytes changed
so the running resolver consumes them.

## Evidence Pointers

These pointers locate the current Orbit code paths that implement this contract.

### Current Orbit

These files implement the bootstrap, builder, reconciler, and probe described above.

- `apps/gateway/app/Console/Commands/Internal/BootstrapGatewayLocalCommand.php` — current bootstrap path.
- `apps/gateway/app/Services/Vpn/WgEasyServiceInstaller.php` — wg-easy install service.
- `apps/gateway/app/Services/Dns/DnsmasqBaseConfigBuilder.php` — tool-owned base builder.
- `apps/gateway/app/Services/Dns/NodeDnsmasqRecordsBuilder.php` — node projection builder.
- `apps/gateway/app/Services/Dns/ProxyDnsmasqRecordsBuilder.php` — proxy projection builder.
- `apps/gateway/app/Services/Dns/OrbitDnsServiceInstaller.php` — orbit-dns install service.
- `apps/gateway/app/Services/Dns/DnsmasqReconciler.php` — reconciler.
- `apps/gateway/app/Services/Doctor/` — node, proxy, and tool DNS probes.
- `apps/gateway/app/Tools/DnsTool.php` — `dns` tool definition.
