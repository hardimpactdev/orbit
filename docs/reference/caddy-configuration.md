---
title: "Caddy configuration"
description: "How Orbit renders each Node's Caddyfile, submits native publication, and reports failures."
covers:
  - apps/gateway/app/Infrastructure/Caddy/**
  - apps/gateway/app/Console/Commands/CaddyBuildCommand.php
  - apps/gateway/app/Infrastructure/Nodes/Roles/CaddyRoleFailure.php
  - apps/gateway/app/Domain/Nodes/CaddyRelease.php
  - swamp/ansible/{route-create-site.yml,route-withdraw.yml,library/orbit_route_site.py,library/orbit_route_withdraw.py,module_utils/orbit_route_withdraw.py}
  - swamp/ansible/tasks/{caddy-publish,gateway-swamp}.yml
  - swamp/workflows/workflow-{operation-route-caddy,operation-route-caddy-delta,route-create-ingress}.yaml
---

# Caddy configuration

Orbit owns `/etc/caddy/Caddyfile` on every Node that serves sites through Caddy. The Gateway renders the complete file from committed database state and publishes it over pinned SSH with validation before replacement. [Node provisioning](/reference/node-provisioning#package-sources) describes how Orbit installs the pinned Caddy package (floor `2.9.0`).

## Native publication

Native publication writes a temporary candidate file (`.orbit-candidate-*`) in `/etc/caddy/` with `root:caddy` ownership and mode `0640`, validates it with `caddy validate` as the `caddy` user, atomically renames it over `/etc/caddy/Caddyfile` (`mv -Tf`), and reloads Caddy.

- If validation fails, the candidate is removed, `/etc/caddy/Caddyfile` stays byte-identical, and reload is skipped.
- If reload fails, the validated file remains on disk and retrying completes activation.
- Route withdrawal and container clone publication hold a per-Node lock, verify the composed-from checksum, and update only the Route's own site groups ([coordinate publication](/reference/routes#coordinate-publication)).
- Replacement manages Unix owner, group, and mode only; operator-added ACLs or extended attributes are not preserved.

Manual builds run via `php artisan orbit:caddy-build <node> [--dry-run] [--diff]` on the Gateway host (`route.caddy_converge`). `--dry-run` prints the rendered Caddyfile without mutating the Node, and `--diff` compares it against the live file via `caddy.read-live`. Gateway releases switch code without converging Caddy sites; [`node:converge`](/cli/node/converge) publishes the Gateway Node's sites like any other Node.

## Published layout

Every managed Caddyfile begins with `# Managed by Orbit: Node Caddy build`, Orbit's fixed global options block, and then each site block on that Node:

```caddy
# Managed by Orbit: Node Caddy build
{
    auto_https disable_certs
    order abort first
    metrics {
        per_host
    }
}
```

| Global option | Effect |
| --- | --- |
| `auto_https disable_certs` | Keeps HTTP-to-HTTPS redirects while disabling automatic public ACME certs except on public Ingress sites. |
| `order abort first` | Runs client-range `abort` guards before every other handler. |
| `metrics { per_host }` | Collects per-hostname HTTP metrics on every Node (scraped on selected `ingress` Nodes; see [Service metrics](/reference/service-metrics#caddy-traffic)). |

On container Gateways, the Gateway site imports the active release's handlers so release switches update only the upstream and reload host Caddy. Before freezing an Instance with a Route, the [executor](/reference/executor#signed-power-delivery) queries Caddy's loopback admin API (`127.0.0.1:2019/config/`, sending the full `Host` with port `2019`) to confirm the asleep marker rule.

## Node Caddy build

The Gateway renders sites deterministically from committed database state in fixed order: Gateway web, Metrics, service metrics, Routes, WebSocket, analytics, and then extension [`ContributedCaddySiteSource`](/reference/extensions#plug-in-points) sources (such as AI router). Role sites render while active, provisioning, or failed, and stop rendering once role removal starts; `websocket`, `analytics`, `metrics`, and AI router sites wait until their certificate record is present.

| Site source | Nodes | Listener |
| --- | --- | --- |
| `app-dev` / `app-prod` workloads, Routers, custom proxies, analytics tracking, Vite, wake | Workload and Router Nodes | WireGuard IP, plus LAN IP when present |
| Public Ingress sites | Cluster Ingress Node | `0.0.0.0`, WireGuard IP, plus LAN IP when present |
| `gateway.orbit`, `metrics.orbit` | `gateway` Node | WireGuard IP |
| Service metrics scrape (`:9103`) | Selected `ingress` Node | WireGuard IP |
| `reverb.orbit`, `reverb.orbit:8443`, `analytics.orbit`, `collector.ai-router.orbit` | Role or collector Node | WireGuard IP |
| Container clone own sites (`:443`, `:444`) | Clone's Node | Instance bridge gateway IP |

For nested Laravel [application directories](/reference/projects#application-directory) (such as `apps/site/public`), Caddy serves the nested web root and validates `storage` symlinks without exposing `.git`. A Node allows at most one site per domain, port, and listener; duplicate sites fail render before touching the Node.

### Listener addresses

Only public Ingress sites bind `0.0.0.0` (plus WireGuard and LAN). All private sites bind only explicit WireGuard, LAN, or container bridge addresses and apply an `abort` client guard right after `bind`:

| Site | Admitted clients |
| --- | --- |
| `gateway.orbit`, `metrics.orbit`, service metrics, `reverb.orbit`, `analytics.orbit`, `collector.ai-router.orbit` | VPN subnet only |
| Router and workload sites on an `ingress` Node | `private_ranges`, `100.64.0.0/10`, and VPN subnet |
| Router and workload sites on non-`ingress` Nodes, and public Ingress sites | Any client (firewall enforces access) |
| Container clone own sites on the bridge gateway | That clone's bridge IP only |

Every managed Linux Node allows non-local binds ([kernel policy](/reference/node-provisioning#kernel-policy)), so container bridge sites load cleanly even while the bridge is down. Give Nodes with a stored LAN IP a static address or DHCP reservation so `caddy.check-addresses` never fails on a drifted IP.

### Certificates and role transitions

Certificate publication runs before Caddy publication when adding a site, and certificate removal runs after withdrawal (`app-dev.certificate_in_use` blocks removing a certificate still referenced by a site). `caddy.install-certificate` and `caddy.remove-certificate` manage `/etc/caddy/orbit-<scope>-cert-current` (`root:caddy`, directory `0750`, files `0640`) atomically under `/run/lock/orbit/caddy.lock` (30-second wait).

When relocating `websocket`, the old Node keeps serving `reverb.orbit` (with dual broadcasts from the Gateway) until the new Node is live, private DNS points to it, and the 31-second [withdrawal grace period](/reference/routes#withdrawal-grace-period) passes.

### When Caddy is absent

Caddy roles are `gateway`, `router`, `ingress`, `app-dev`, `app-prod`, `websocket`, `analytics`, and `swamp`. On a Node without `/usr/bin/caddy` and without a running `caddy` service, a build skips cleanly when no site renders or no Caddy role is active or converging, moving any stale `/etc/caddy/Caddyfile` aside into `/etc/caddy/orbit-backups/<UTC timestamp>/` so a later start cannot fail on removed certificates.

### When a build fails

Because each Node has one Caddyfile, a broken site (such as a missing certificate) blocks Caddy updates on that Node until fixed. Failures carry the publisher's error code (`app-dev.caddy_config_failed`, `app-prod.caddy_config_failed`, `router.caddy_config_failed`, `ingress.caddy_config_failed`, `websocket.caddy_publication_failed`, `analytics.caddy_publication_failed`, `ai_router.caddy_publication_failed`, or `metrics.caddy_publication_failed`) with up to 2,000 bytes of Caddy's message. [Doctor](/cli/doctor/doctor#what-each-family-checks) checks Caddy service liveness; use `orbit:caddy-build <node> --dry-run --diff` to inspect configuration drift.

## Public Ingress certificates

Public Ingress sites add `tls force_automate` so Caddy manages Let's Encrypt certificates automatically despite the global `auto_https disable_certs` option. Point public DNS at the Ingress Node; Orbit opens TCP ports `80` and `443` while the Cluster has an active public Route. Private sites never use `force_automate` and always pin Orbit CA files.

## Replaced configuration

Orbit replaces any unmanaged `/etc/caddy/Caddyfile` rather than adopting it, backing up non-default files to `/etc/caddy/orbit-backups/<UTC timestamp>/`. Move hand-placed sites into Orbit as [custom proxy Routes](/reference/routes#custom-proxy-routes) before running a build.

## Why it works this way

### Orbit owns the global options

Rendering one deterministic Caddyfile per Node from committed database state keeps builds reproducible and prevents hand-placed global options (`auto_https off`, `local_certs`, custom ACME issuers) from breaking Orbit's private-vs-public certificate model. Enabling `metrics { per_host }` globally costs microseconds per request while keeping one global block across every Node, and pinning Caddy (`>= 2.9.0`) from GitHub releases guarantees directives such as `log_skip` and `tls force_automate`.
