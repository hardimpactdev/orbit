# DNS Bootstrap Contract

[Back to tool catalog.](catalog/README.md)
[Back to tool family.](README.md)

This contract spells out how Orbit provisions DNS for development names over
the VPN. The runtime shape is `wg-easy` + `orbit-dns` + `dnsmasq.conf`, kept in
sync with fleet state and verified by `doctor`. It is referenced from
[`catalog/dns.md`](catalog/dns.md) and from the gateway bootstrap path
(`orbit:internal:bootstrap-gateway-local`).

DNS *commands* — `dns:resolve-tld`, `dns:list` — stay caller-local and are
covered by `docs/domains/16_dns/**`. The **node family** owns development and
agent TLD DNS records (per
[Architecture: DNS responsibilities](../../architecture.md#dns-responsibilities));
the **tool family** owns the runtime substrate (the `orbit-dns` container and
`dnsmasq.conf` artifact rendered from node-family state); **bootstrap** wires
the two together at gateway install. Stable private `.orbit` service names are
service contracts owned by the router, not by this `dns` tool contract.

## Ownership

This table maps each responsibility for development DNS over the VPN to the
service or command that owns it.

| Concern                                         | Owner                                                                 |
| ----------------------------------------------- | --------------------------------------------------------------------- |
| Installing `wg-easy` on the gateway             | `BootstrapGatewayLocalCommand` → `WgEasyServiceInstaller`             |
| Installing `orbit-dns` on the gateway           | `BootstrapGatewayLocalCommand` → `OrbitDnsServiceInstaller`           |
| Generating `dnsmasq.conf` from fleet state      | `DnsmasqConfigBuilder` (pure function over `Node` rows)               |
| Reconciling `dnsmasq.conf` after fleet changes  | `DnsmasqReconciler` invoked from gateway-side `node:new/:update/:remove` actions |
| Probing runtime drift                           | Doctor `dns` runtime probe under `doctor --family=tool`               |
| Restoring / adopting `dns` drift                | The same probe's restore script (rewrite + restart) and adopt script  |

## Bootstrap Step Order

The gateway bootstrap runs in this order:

1. WireGuard kernel + interface install.
2. Root CA materialized.
3. Gateway `orbit-runtime` container created and started.
4. Gateway `orbit-caddy` container created, started, and configured to route
   HTTPS gateway traffic to `orbit-runtime` over the Docker network.
5. **`wg-easy` container started.**
6. **`orbit-dns` container started inside wg-easy's network namespace,
   with the initial `dnsmasq.conf` rendered from current fleet state.**
7. Gateway environment marked (`ORBIT_IS_GATEWAY=true`).

`wg-easy` must come up before `orbit-dns`: `orbit-dns` uses
`network_mode: container:wg-easy`, which pins the netns of the wg-easy
container. If wg-easy is not running, `docker compose up -d` for orbit-dns
fails. `OrbitDnsServiceInstaller` enforces this precondition.

## `wg-easy` Provisioning Shape

Compose path: `~/.config/orbit/wg-easy/docker-compose.yaml`.

Required envs / settings:

- `INIT_ENABLED=true` — enables the unattended setup flow in wg-easy v15.
- `INIT_USERNAME=orbit` and `INIT_PASSWORD=<generated>` — bootstrap the admin
  account. The generated password is persisted in `apps/gateway/.env` as
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
- `volumes: [<dnsmasq.conf path>:/etc/dnsmasq.conf:ro]`
- `cap_add: [NET_ADMIN]`
- `restart: unless-stopped`

The initial `dnsmasq.conf` is rendered from fleet state before
`docker compose up -d`, so the container starts with a valid config.

## `dnsmasq.conf` Shape

Rendered by `DnsmasqConfigBuilder::build(Collection $nodes): string`. The
output is deterministic and contains:

- One `address=/{tld}/{wireguard_address}` line per node that carries an
  active `app-development` or `agent` role assignment and has both `tld` and
  `wireguard_address` set. Nodes missing either field, or carrying no
  TLD-supporting active role, are skipped.
- Optional `local=/{tld}/` companions per TLD.
- `no-resolv` + upstream resolvers (`server=1.1.1.1`, `server=8.8.8.8`).
- `conf-dir=/etc/dnsmasq.d/,*.conf` (preserves operator drop-ins, if any).
- `log-queries` + `log-facility=-`.

Lines are emitted in stable order (alphabetical by TLD) so two builder calls
on the same inputs produce byte-identical output.

## Reconciliation

`DnsmasqReconciler::reconcile()`:

1. Reads current `Node` rows and active role assignments.
2. Builds a candidate `dnsmasq.conf` via `DnsmasqConfigBuilder`.
3. If different from the on-disk file, writes the new content.
4. Restarts dnsmasq with `docker restart orbit-dns`. dnsmasq reloads hosts on
   `SIGHUP`, but address rules require a restart to take effect reliably.
5. Skips the restart if the file was already up to date.

Triggers (only on a gateway — `config('orbit.is_gateway')` must be true):

- `node:new` for any node that carries a TLD-supporting role and has `tld` and `wireguard_address` set.
- `node:update` when `tld` or `wireguard_address` change.
- `node:remove` for any node.
- `node role:add` when the added role depends on `tld` (`app-development` or `agent`).
- `node role:update` when the role-assignment change activates or deactivates a TLD-supporting baseline.
- `node role:remove` when removing the last TLD-supporting role from a node.

The reconciler is idempotent: running it twice in a row is a no-op for the
second call.

## Doctor Contract

`doctor --family=tool` runs three checks for the `dns` runtime:

| Drift kind                  | Detection                                                          | Restorable | Adoptable |
| --------------------------- | ------------------------------------------------------------------ | ---------- | --------- |
| `tool.dns_container_missing`  | `orbit-dns` not in `docker ps -a`.                                 | Yes (rerun installer). | No |
| `tool.dns_port_not_listening` | `orbit-dns` running but no listener on `53` inside wg-easy's netns. | Yes (restart container). | No |
| `tool.dns_config_drift`       | `dnsmasq.conf` differs from `DnsmasqConfigBuilder` output for current DB state. | Yes (rewrite + restart). | Yes (parse file into `(node, tld, wireguard_address)` triples and adopt into node-family state). |

`tool.dns_config_drift` is the only adoptable drift, and the use case is narrow:
an operator hand-edited the file for an emergency and now wants Orbit to adopt
the new content as the source of truth. Adoption parses the observed
`dnsmasq.conf` into `(node, tld, wireguard_address)` triples and records those
into **node-family** state. The tool family does not own DNS records; the node
family does (see
[Architecture: DNS responsibilities](../../architecture.md#dns-responsibilities)).
After adoption, `dnsmasq.conf` is re-rendered from the canonical node state so
the file and DB stay in lockstep.

## Why install/remove are not operator commands

`tool:install dns` and `tool:remove dns` are reachable only through this
bootstrap path. Operator-facing install/remove would let a user remove the
DNS substrate from a running gateway and break every WG client's resolver
without a doctor signal first. The catalog reflects that constraint; this
contract is the explicit exception that authorizes bootstrap to call the
underlying installer.

`tool:update dns` re-runs the installer (which re-emits the compose file +
rebuilds `dnsmasq.conf` from current state and `docker compose up -d`).

## Evidence Pointers

These pointers locate the current Orbit code paths that implement this contract.

### Current Orbit

These files implement the bootstrap, builder, reconciler, and probe described above.

- `apps/gateway/app/Console/Commands/Internal/BootstrapGatewayLocalCommand.php` — current bootstrap path.
- `apps/gateway/app/Services/Vpn/WgEasyServiceInstaller.php` — wg-easy install service.
- `apps/gateway/app/Services/Dns/DnsmasqConfigBuilder.php` — pure builder.
- `apps/gateway/app/Services/Dns/OrbitDnsServiceInstaller.php` — orbit-dns install service.
- `apps/gateway/app/Services/Dns/DnsmasqReconciler.php` — reconciler.
- `apps/gateway/app/Services/Doctor/` — DNS runtime probe (`DnsRuntimeProbe`).
- `apps/gateway/app/Tools/DnsTool.php` — `dns` tool definition.
