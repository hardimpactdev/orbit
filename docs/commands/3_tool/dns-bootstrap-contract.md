# DNS Bootstrap Contract

[Back to tool catalog.](catalog/README.md)
[Back to tool family.](README.md)

This contract spells out how Orbit's gateway-side DNS infrastructure
(`wg-easy` + `orbit-dns` + `dnsmasq.conf`) is provisioned, kept in sync with
fleet state, and verified by `doctor`. It is referenced from
[`catalog/dns.md`](catalog/dns.md) and from the gateway bootstrap path
(`orbit:internal:bootstrap-gateway-local`).

DNS *commands* — `dns:resolve-tld`, `dns:list` — stay caller-local and are
covered by `docs/abstractions/16_dns.md`. Gateway DNS infrastructure is owned
here by the **tool family** + **node family** + **bootstrap**.

## Ownership

| Concern                                         | Owner                                                                 |
| ----------------------------------------------- | --------------------------------------------------------------------- |
| Installing `wg-easy` on the gateway             | `BootstrapGatewayLocalCommand` → `WgEasyServiceInstaller`             |
| Installing `orbit-dns` on the gateway           | `BootstrapGatewayLocalCommand` → `OrbitDnsServiceInstaller`           |
| Generating `dnsmasq.conf` from fleet state      | `DnsmasqConfigBuilder` (pure function over `Node` rows)               |
| Reconciling `dnsmasq.conf` after fleet changes  | `DnsmasqReconciler` invoked from gateway-side `node:new/:update/:remove` actions |
| Probing runtime drift                           | Doctor `dns` runtime probe under `doctor --family=tool`               |
| Restoring / adopting `dns` drift                | The same probe's restore script (rewrite + SIGHUP) and adopt script   |

## Bootstrap Step Order

The gateway bootstrap runs in this order:

1. WireGuard kernel + interface install.
2. Root CA materialized.
3. Gateway API runtime (PHP-FPM pool + Caddy site).
4. **`wg-easy` container started.**
5. **`orbit-dns` container started inside wg-easy's network namespace,
   with the initial `dnsmasq.conf` rendered from current fleet state.**
6. Gateway environment marked (`ORBIT_IS_GATEWAY=true`).

`wg-easy` must come up before `orbit-dns`: `orbit-dns` uses
`network_mode: container:wg-easy`, which pins the netns of the wg-easy
container. If wg-easy is not running, `docker compose up -d` for orbit-dns
fails. `OrbitDnsServiceInstaller` enforces this precondition.

## `wg-easy` Provisioning Shape

Compose path: `~/.config/orbit/wg-easy/docker-compose.yaml`.

Required envs / settings:

- `INIT_ENABLED=true` — enables wg-easy v15 unattended setup.
- `INIT_USERNAME=orbit` and `INIT_PASSWORD=<generated>` — bootstrap the admin
  account. The generated password is persisted in the gateway's `.env` as
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

- One `address=/.{tld}/{wireguard_address}` line per node with both
  `tld` and `wireguard_address` set. Nodes missing either field are skipped.
- Optional `local=/{tld}/` companions per TLD.
- `no-resolv` + upstream resolvers (`server=1.1.1.1`, `server=8.8.8.8`).
- `conf-dir=/etc/dnsmasq.d/,*.conf` (preserves operator drop-ins, if any).
- `log-queries` + `log-facility=-`.

Lines are emitted in stable order (alphabetical by TLD) so two builder calls
on the same inputs produce byte-identical output.

## Reconciliation

`DnsmasqReconciler::reconcile()`:

1. Reads current `Node` rows.
2. Builds a candidate `dnsmasq.conf` via `DnsmasqConfigBuilder`.
3. If different from the on-disk file, writes the new content.
4. Sends `SIGHUP` to dnsmasq: `docker exec orbit-dns kill -HUP 1`. `SIGHUP`
   is preferred over restart — dnsmasq re-reads the config file in place
   without dropping in-flight queries.
5. Skips the SIGHUP if the file was already up to date.

Triggers (only on a gateway — `config('orbit.is_gateway')` must be true):

- `node:new` for any role where `tld` and `wireguard_address` are set.
- `node:update` when `tld` or `wireguard_address` change.
- `node:remove` for any node.

The reconciler is idempotent: running it twice in a row is a no-op for the
second call.

## Doctor Contract

`doctor --family=tool` runs three checks for the `dns` runtime:

| Drift kind                  | Detection                                                          | Restorable | Adoptable |
| --------------------------- | ------------------------------------------------------------------ | ---------- | --------- |
| `tool.dns_container_missing`  | `orbit-dns` not in `docker ps -a`.                                 | Yes (rerun installer). | No |
| `tool.dns_port_not_listening` | `orbit-dns` running but no listener on `53` inside wg-easy's netns. | Yes (restart container). | No |
| `tool.dns_config_drift`       | `dnsmasq.conf` differs from `DnsmasqConfigBuilder` output for current DB state. | Yes (rewrite + SIGHUP). | Yes (record observed content as intent). |

`tool.dns_config_drift` is the only adoptable drift, and the use case is narrow:
an operator hand-edited the file for an emergency and now wants Orbit to
adopt the new content as the source of truth. Adoption persists the observed
content into the corresponding DB state so future builds match.

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

- `app/Console/Commands/Internal/BootstrapGatewayLocalCommand.php` — current bootstrap path.
- `app/Services/Vpn/WgEasyServiceInstaller.php` — wg-easy install service.
- `app/Services/Dns/DnsmasqConfigBuilder.php` — pure builder.
- `app/Services/Dns/OrbitDnsServiceInstaller.php` — orbit-dns install service.
- `app/Services/Dns/DnsmasqReconciler.php` — reconciler.
- `app/Services/Doctor/` — DNS runtime probe (`DnsRuntimeProbe`).
- `app/Tools/DnsTool.php` — `dns` tool definition.
- Old evidence: `../orbit-old-may/app/Services/RemoteProvisioner.php:947` — original `network_mode: container:wg-easy` rationale.
- Old evidence: `../orbit-old-may/app/Services/DnsmasqConfigGenerator.php` — original generator shape.
- Old evidence: `../orbit-old-may/app/Services/DoctorService.php:692` — original "orbit-dns container not found" failure.
- Old evidence: `../orbit-old-may/app/Console/Commands/TldSyncCommand.php` — original lifecycle hook (the control-side fetcher we are *not* porting; gateway authoritative DB row replaces it).
