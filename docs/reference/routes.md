---
title: "Routes"
description: "What a Route records, how Orbit projects private and public traffic through a Node, Router, or Ingress, and which changes Orbit coordinates or refuses."
covers:
  - apps/gateway/app/{Domain,Actions,Infrastructure,Data}/Routes/**
  - apps/gateway/app/Http/{Controllers/Api/RoutesController.php,Requests/Routes/**}
  - apps/gateway/{app/Models/{Route,RouteTarget,RouteCustomProxy}.php,database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds.php,*_route_withdrawal_holds_table.php}}
  - apps/gateway/app/Infrastructure/{AppDev/{DevelopmentCaddyConfigRenderer,DevelopmentSiteRepository,NativeDevelopmentProjectionOperationLock,RemoteAppDevCertificateManager,RemoteAppDevRouteFirewallManager},Certificates/GatewayLeafCertificateSigner}.php
  - apps/gateway/app/{Domain/AppDev/{DevelopmentServerEndpoint,PrivateDnsAnswerExpiry}.php,Infrastructure/Clusters/NativeClusterRouterOperationLock.php}
  - apps/gateway/app/Infrastructure/Instances/{NativeDevelopmentRouteProjector,NativeDevelopmentSourceAccess}.php
  - apps/gateway/{app/Domain/Instances/DevelopmentSourceAccess.php,database/migrations/*_instance_path_proxies_table.php}
  - swamp/{ansible/route-create-site.yml,ansible/library/orbit_route_site.py,ansible/{library,module_utils}/orbit_route_withdraw.py,workflows/workflow-{operation-route-create,route-create-site,route-create-csr,route-certificate-publish,node-role-certificate-publish}.yaml}
---

# Routes

A Route maps a domain to an Instance or a Node service. This page explains the Route record, domain and scope selection, private and public traffic, multi-root serving, custom proxies, and how Orbit coordinates updates and removals. See [`route`](/cli/route) for the CLI commands.

A Route has one of three immutable kinds:

| Kind | Owner | Serves |
| --- | --- | --- |
| `app` | A Project | One Instance, or an ordered pool of production Instances. Created automatically for web-serving Projects (`laravel-app`, `symfony-app`). |
| `custom_proxy` | A Node or Cluster | A service on a Node's loopback address or a Node Process. See [Custom proxy Routes](#custom-proxy-routes). |
| `analytics_tracking` | One Instance | Plausible script and event paths. See [Analytics](/reference/analytics#publish-a-tracking-host). |

Each active Instance of a web-serving Project has exactly one Route without a `web_root` (its own Route). It can also have extra Routes with a `web_root` to [serve additional directories](#serve-several-web-roots).

```bash
orbit route:create 12 shop.example.test
orbit route:create 14 shop.example.com --publication=public
orbit route:create executor.orbit --node=7 --upstream=http://127.0.0.1:4788
```

## Route record

The Gateway stores these fields for each Route (`route:show` returns them without exposing infrastructure IP addresses):

| Field | Meaning |
| --- | --- |
| `kind` | `app`, `custom_proxy`, or `analytics_tracking`. |
| `project_id` | Owning Project for `app` Routes (`null` for other kinds). |
| `node_id` or `cluster_id` | Exactly one Node or active Cluster scope. |
| `domain` | Unique normalized domain. Immutable; domain changes create a replacement Route. |
| `web_root` | `null` (Instance's effective root) or a repository-relative path such as `apps/docs/public`. |
| `provenance` | Immutable `generated` or `explicit`. |
| `generation_basis_node_id` | For generated Routes, the Node whose TLD derives the domain. |
| `publication` | `private` or `public` (kept even when targetless). |
| `status` | `pending`, `active`, `activating`, `retiring`, or `failed`. |
| `failed_step`, `error_code` | The step and code that stopped an incomplete Operation. |
| `replaces_route_id`, `replaced_by_route_id`, `replacement_step` | Replacement pair links and progress during a domain or placement change. |
| `target_set_step` | Progress step of a production target-set change. |
| `targets` | Ordered Instance targets (only explicit production Routes allow more than one). `target` repeats the first entry. |
| `analytics_instance_id` | Target Instance for `analytics_tracking` Routes (`null` otherwise). |
| `upstream`, `process_id` | Loopback URL or Node Process for `custom_proxy` Routes. |

### Check Route status with Doctor

Use the Route Operation result to inspect publication and lifecycle errors. [Doctor](/cli/doctor/doctor#what-each-family-checks) checks role and Process liveness only; it does not repeat Route lifecycle, site, certificate, DNS, firewall, forwarding, or configuration validation.

## Select a domain and scope

Pass `--domain` when creating an Instance to create an explicit Route. Otherwise Orbit generates a domain from the effective top-level domain (TLD):

1. The TLD of the Node's active Cluster, if set.
2. Otherwise the Node's own TLD.

If neither exists, creation stops before changing anything (`app-prod` Nodes do not need a TLD because production clones supply explicit domains). The source branch never changes the generated domain:

| Instance name | Generated domain (TLD `test`) |
| --- | --- |
| `default` | `<project>.test` |
| Any other name | `<instance>.<project>.test` |

The Conn [Project directory and name check](/reference/conn#threads-in-an-orbit-project) previews this same effective TLD. Cluster membership decides routing scope independently of TLD: a Node in an active Cluster uses Cluster scope (requiring one active Router), and any other Node uses Node scope. See [cluster](/cli/cluster#placement-and-tlds).

### Generated domains after a Project slug update

Updating a Project's `slug` recomputes every generated development Route domain from the new slug, Instance name, and effective TLD by creating replacement Routes. Explicit domains never change, and default-branch updates change no Routes. See [Update a Project](/reference/projects#update-a-project).

## Create and change targets

Create an explicit app Route with `route:create <instance> <domain> [--publication=private|public] [--web-root=PATH]` (API `POST /api/v1/routes` with `instance_id`, `domain`, optional `publication`, and optional `web_root`). Publication defaults to `private`, and the Instance determines the Project and Node/Cluster scope.

Creation reserves a `pending` Route and starts an Operation. It generates a Node key and CSR, signs it with the Gateway CA (keys never leave the Node), configures LAN rules if clustered, installs certificates and Caddy config, probes TLS, opens public Ingress if `public`, and publishes private DNS. A failed step leaves the Route `pending` with `failed_step` and `error_code` (there is no rollback); repeat the identical request to retry. An identical retry on an active Route returns it with no Node changes. Retrying a `failed` or `retiring` Route returns `route.activation_unsupported` (409).

When `route.create` is enabled in `ORBIT_ENGINE_KINDS` and the Route needs only site publication and private DNS (no Ingress edge or LAN rules), the [Gateway engine](/reference/gateway-engine#routecreate) executes it directly via typed Node executor actions. A private Route's `--domain` change runs there too, as [route.update](/reference/gateway-engine#routeupdate): the Gateway commits the cutover as its own step, and a retry after it runs only the post-cutover steps and never starts on Swamp.

| Creation refusal | Meaning |
| --- | --- |
| `route.domain_invalid` | Invalid domain (HTTP validation also returns `422 validation.failed`). |
| `route.domain_conflict` | Domain is already owned or reserved (`gateway.orbit`, `metrics.orbit`, `reverb.orbit`, `analytics.orbit`, `collector.ai-router.orbit`). |
| `route.retry_conflict` | Domain already exists with a different Instance, publication, or custom proxy configuration. |
| `route.activation_unsupported` | Retrying creation on a `failed` or `retiring` Route. |
| `route.scope_required` | Private custom proxy requires a Node; public custom proxy requires a Process and optional Ingress. |
| `route.target_inactive` | Target Instance is not active. |
| `route.target_web_root_unsupported` | Target Instance has no supported relative web root (such as `.`). |
| `route.target_conflict` | Target Instance already has a Route without a web root. |
| `route.web_root_release_missing` | Production `web_root` Route requested before the Instance has a selected release. |
| `route.web_root_unsafe` | Production web root would expose `.env` or sits in the default directory without matching the Instance root. |
| `app-prod.web_root_invalid` | Production release is missing the web root or application directory, or contains a symlink. |
| `route.router_required` | Cluster has no active Router. |
| `route.instance_inactive` | Instance is neither provisioning nor active. |
| `route.node_inactive` | Custom proxy Node is inactive or lacks a WireGuard address. |
| `route.upstream_invalid` | Upstream is not a loopback HTTP URL. |
| `route.upstream_unresolved` | Process has no single supported TCP listener for this publication. |
| `route.process_conflict` | Process is not a Node Process on the serving Node. |

Single-target updates (`route:target:set` and `route:target:unset`) follow these rules:

| Change | Result |
| --- | --- |
| Set the current target again | Returns the unchanged Route with no Node run. |
| Clear an empty Route (`route:target:unset`) | Record-only; returns the unchanged Route with no Operation. |
| Set an Instance owned by another Route | `route.target_conflict`. |
| Detach an active Instance or unset a pending replacement | `route.target_conflict` (use `route:destroy` to abandon a pending replacement). |
| Change the target of an `active` Route | `route.reconciliation_required`. |
| Set another Project's or an inactive Instance | `route.target_app_conflict` or `route.target_inactive`. |
| Set a generated target without an effective TLD | `route.tld_required`. |
| Change a non-`app` Route | `route.kind_unsupported`. |
| Set a target while another Instance environment operation runs | `env.operation_busy`. |
| Publish step fails on a published development Route | `route.publish_failed`; new target stays recorded for retry. |

Setting a target on a generated Route moves its generation basis, scope, and domain with the target (creating a replacement Route if the domain changes). On unpublished Routes, `route:target:set` only records the target; on published development Routes, it republishes the site. See [Native Route targets](/reference/native-route-targets).

### Change a production target set

Send the complete ordered Instance list for an explicit, Cluster-scoped production Route. Every target must be an active production Instance of the same Project on distinct active `app-prod` Nodes in that Cluster.

Any detached Instance must appear in `dispositions` (either reassigned to a compatible explicit Route or marked `remove: true`). Orbit stages each added target's runtime, certificates, Caddy site, and `.env`, probes TLS, switches the Router pool, and retires unused targets. There is no rollback: failures before the Router switch keep original associations; failures after the switch commit the new associations and record `failed_step` for an identical retry.

| Error code | Meaning |
| --- | --- |
| `route.pool_unsupported` | Route or target cannot join a production pool (such as generated or `app-dev`). |
| `route.target_conflict` | Duplicate Instance or two targets on the same Node. |
| `route.target_app_conflict` | Target or reassignment Route belongs to another Project. |
| `route.target_scope_conflict` | Target or destination is outside the Cluster or not on an active `app-prod` Node. |
| `route.target_inactive` | Target Instance is missing or inactive. |
| `route.target_web_root_unsupported` | Target Instance lacks a supported relative web root. |
| `route.target_disposition_required` | Detached Instance has no disposition. |
| `route.target_disposition_invalid` | Disposition names an invalid destination or both reassigns and removes. |
| `route.target_set_conflict` | Another target-set change is already in progress on the Route. |
| `route.target_set_recovery_required` | Previous target-set failure must be retried or recovered first. |
| `route.web_root_unsupported` | Routes with a `web_root` keep a single target. |

### Serve a production pool

Router Caddy load-balances production pools with round-robin and never replays failed requests. A connection or TLS failure marks that target unhealthy for 10 seconds; application HTTP errors (such as 500) do not exclude a target. Empty pools and backend connection errors return HTTP `503` with `Orbit Route unavailable` (never exposing backend IPs). Round-robin has no sticky sessions, so pooled apps must share a session store.

Containerized Instances are served by FrankenPHP inside the container, proxied by Node Caddy over the container bridge on port 8080. Development container sites check the asleep marker and forward sleeping requests to the wake endpoint (holding until awake and redirecting `307`, or returning `503` on wake failure). Private clone Routes also open a port-444 development proxy for container dev ports (see [Agent development servers](/reference/development-containers#agent-development-servers)).

## Serve several web roots

One Instance checkout or release can serve multiple sites by creating extra Routes with `--web-root=PATH` (API `web_root`; `null` clears it):

```bash
orbit route:create 12 docs.shop.test --web-root=apps/docs/public
orbit route:update 14 --web-root=apps/admin/public
```

A `web_root` follows [Project root](/reference/projects#fields) validation rules (relative path, no `.` or `..`, no symlinks). Missing or symlinked roots fail with `app-dev.source_access_failed` in development or `app-prod.web_root_invalid` in production.

| Aspect | Behavior |
| --- | --- |
| Own Route | Each `laravel-app` or `symfony-app` keeps one Route without `web_root` (`route.web_root_conflict` if altered). |
| Certificates | Each web-root Route gets its own leaf (`route-<id>`). |
| PHP-FPM | One pool per application directory (`orbit-app-instance-<id>-<suffix>`), shared across Routes serving that directory. |
| `APP_URL` | Written into directories containing `artisan`, using the oldest Route serving that directory. |
| Lifecycle | Instance removal deletes web-root Routes first; transfers move them with the Instance (public ones stay in their Cluster); any Route wakes a hibernated Instance. |

A Route with a `web_root` cannot change its domain (`route.web_root_domain_immutable`), and `web_root` must be updated alone (`route.web_root_update_separate`). Invalid transitions for `web_root` also refuse with `route.web_root_unsupported` or `route.target_conflict`.

### Web roots on production

Production web roots serve from `<home>/current/<web_root>`. Deploy the Instance first (`route.web_root_release_missing` if `current` is absent). Each directory gets its own pool under the Instance's PHP-FPM master (`orbit-<user>-<suffix>`) and stable `<home>/env/<directory>/.env` linked into each release. No web root may contain a `.env` or nest inside the primary web root (`route.web_root_unsafe`). A Route repeating the primary Instance root reuses the primary pool and `<home>/.env`. Failed creations become `failed` and must be removed with `route:destroy`.

## Custom proxy Routes

A custom proxy Route exposes a domain for a service already running on a managed Node, without creating a Project or PHP-FPM pool:

```bash
orbit route:create executor.orbit --node=7 --upstream=http://127.0.0.1:4788
orbit route:create executor.orbit --node=7 --process=21
```

| Rule | Behavior |
| --- | --- |
| Domain | Any fleet-unique DNS domain (no `.orbit` or TLD required; reserved platform names are blocked). |
| Scope | Private uses the serving Node; public uses the selected Ingress's Cluster. |
| Upstream | Private accepts loopback HTTP (`127.0.0.1`, `localhost`, `::1`) or a Node Process listener on that Node. |
| Caddy & DNS | Terminates Orbit CA TLS (private) or Let's Encrypt (public), keeps `Host`, supports streaming and WebSockets, and publishes an exact private DNS record. |
| Guards | Refuses Node removal (`node.has_routes`), Process removal (`process.has_routes`), and Process updates that move the resolved listener (`process.route_upstream_changed` 409). |

To recover a `failed` private custom proxy, destroy and recreate it; for a public Process Route, repeat the create command. Create a custom proxy Route before the first [Node Caddy build](/reference/caddy-configuration#replaced-configuration) if you have hand-placed Caddy sites (see [Migrate an unmanaged Executor hostname](/solutions/migrate-unmanaged-executor-hostname)).

### Stored private TLS upstreams

During a managed service relocation, a private custom proxy can store a backend Node ID and HTTPS WireGuard URL with an explicit port. The serving Node keeps its private domain while forwarding over Orbit CA TLS (preserving `Host`, SNI, streaming, and WebSockets). This is an internal relocation state, not a public `route:create` option, and keeps both Nodes protected from removal.

### Public Process hostnames

Expose a Node-owned Docker Process publicly through a Cluster Ingress:

```bash
orbit route:create sync.example.com --process=267 --publication=public
```

Pass `--ingress=<node>` (Node name or numeric ID; API `ingress_node_id`; `--process` takes a numeric ID) to pick the Ingress explicitly. Otherwise Orbit picks the Process Node's Cluster Ingress, or the sole active public Ingress if outside a Cluster (missing returns `route.ingress_required`, multiple returns `route.ingress_ambiguous`).

If both Nodes share a recorded IPv4 `/24` LAN, Orbit selects the Process Node's LAN address (requiring an active managed `orbit:` TCP allow rule for that Ingress LAN source and port, or refusing with `route.upstream_firewall_mismatch`); otherwise it uses WireGuard. The Process must publish exactly one TCP listener on that address (`route.upstream_unresolved` otherwise; loopback, wildcard, and UDP listeners are ineligible). Activation probes the listener with HTTP HEAD (10-second timeout; failure returns `route.upstream_unreachable`).

Ingress terminates public Let's Encrypt TLS, proxies directly to the Process listener with a 1-hour read/header timeout (supporting streaming and WebSockets), and needs no Router hop. Private DNS points at the Ingress WireGuard address; configure public DNS yourself. Custom proxy Routes cannot change domain, scope, target, or publication after creation.

## Set up private traffic

Orbit prepares runtimes, certificates, Caddy sites, and firewall rules before publishing private DNS to active `vpn` Nodes last, then marks the Route active without waiting for an HTTP 200 from the app.

### Certificate request forms

Node workflows and the agent generate CSRs with common name `Orbit` and a single DNS SAN matching the admitted domain. The Gateway signs the domain into the leaf SAN and accepts both legacy domain-CN and `Orbit`-CN CSRs so rolling upgrades stay compatible.

### Node scope

Private DNS points at the workload Node, where Caddy terminates Orbit CA TLS and serves the web root. Development site preparation grants Caddy read access to the web root and traversal to parent directories while denying the rest of the checkout and `.git` (symlinks inside the web root are refused except Laravel's `public/storage`). Failures restore prior permissions and report `app-dev.source_access_failed`.

#### Caddy access

Development TLS leaves live under `/etc/caddy/orbit-certificates/<scope>` (`root:caddy`, mode `0750`). For host checkouts, the Node agent updates ACLs as the managed user. It denies the checkout and `.git` first, grants read on the web root and Laravel public storage target, and grants traverse on parent directories while preserving masks so `0600` `.env` files stay private. Saved ACL snapshots live under `/var/tmp` and restore automatically on failure (`instance.caddy_access_failed` / `instance.caddy_access_recovery_failed`).

### Cluster scope

Private DNS points the Route domain and Cluster TLD at the Cluster Router (see [Private DNS](/reference/private-dns#cluster-router-addresses)). Router Caddy forwards Orbit CA HTTPS to each workload Node (using LAN if configured, otherwise WireGuard, with no silent fallback) and opens TCP 443 LAN rules scoped by Router source on workload Nodes (see [Route LAN firewall](/reference/route-lan-command)). When Router and workload share a Node, one Caddy instance serves locally without proxying to itself.

### Development-server endpoint

The reserved path `/__orbit/vite` proxies frontend dev assets and HMR over the Route's HTTPS origin (port 443) to the Instance's assigned `vite_port` (see [Assigned Vite ports](/reference/assigned-vite-ports)). Development systemd Processes receive these environment variables:

| Variable | Value |
| --- | --- |
| `ORBIT_DEV_SERVER_ORIGIN` | `https://<route-domain>/__orbit/vite` |
| `DEV_SERVER_ORIGIN` | Same URL for the `vp-dev` preset unless overridden in Process env |
| `ORBIT_DEV_SERVER_HOST` | Route domain |
| `ORBIT_DEV_SERVER_PATH` | `/__orbit/vite` |
| `ORBIT_DEV_SERVER_PORT` | Assigned `vite_port` |

Configure Vite to use `DEV_SERVER_ORIGIN` with `hmr.path: 'hmr'` so `@vite` loads assets from `/__orbit/vite/` over WSS on port 443:

```js
import { defineConfig } from 'vite'

const origin = process.env.DEV_SERVER_ORIGIN
const publicAddress = origin ? new URL(origin) : undefined

export default defineConfig({
    base: publicAddress ? `${publicAddress.pathname}/` : '/',
    server: {
        origin: publicAddress?.origin,
        hmr: publicAddress
            ? {
                protocol: 'wss',
                host: publicAddress.hostname,
                clientPort: 443,
                path: 'hmr',
            }
            : undefined,
    },
})
```

### Private network trust

Every active WireGuard member can reach every other active member over WireGuard (`orbit:wireguard-members`). Access grants authorize Orbit commands and API calls, not private network traffic (except `metrics.orbit`; see [Metrics](/reference/metrics#grafana-access)). On LAN, a Cluster Router admits TCP 443 and DNS (TCP/UDP 53) only from active Cluster members (see [Router LAN firewall](/cli/firewall#router-lan-member-rules)). Public traffic enters only through `ingress` Nodes.

## Publish a public Route

A public app Route terminates Let's Encrypt HTTPS (`tls force_automate`) on the Cluster's sole active `ingress` Node, forwards privately over Orbit CA HTTPS to the Cluster Router, and reaches `app-prod` workloads. Public DNS is managed outside Orbit. Never share `ingress` and `gateway` on the same Node.

The public edge is live only when the Route is `active` or `activating`, Cluster-scoped in an active Cluster with an active Router and serving Ingress, and at or past `replacement_step: public-activated` (finished Routes store `ingress-firewall`). Otherwise the Route keeps `publication: public` without opening public listeners. UFW rules `orbit:ingress-http` (80) and `orbit:ingress-https` (443) stay open only while the Cluster has at least one live public Route.

### Publish the public edge

A publication-only change keeps the Route ID and domain: Orbit verifies private hops, records `public-activated`, rebuilds Ingress Caddy so Let's Encrypt can issue a certificate, and opens the Ingress firewall (`ingress-firewall`). Orbit never falls back to Orbit CA on public sites and does not block on ACME issuance. Changing both domain and publication creates a replacement Route.

### Ingress removal

`node:role:remove NODE ingress --force` refuses with `public_routes_attached` while any Route in its Cluster has `publication: public` (make them private, remove them, or relocate the Ingress instead). Note: making a public app Route private while `ingress` shares a Node with `router` or `app-prod` fails with `caddy.render_failed`; relocate `ingress` first. Removal strips public sites, closes ports 80 and 443, and reopens public SSH if `ingress` was the Node's last role (`--offline` removes on the Gateway side and reports `retained_on_node`).

### Ingress relocation

`node:role:relocate TARGET ingress --force` moves a Cluster's active Ingress to another Cluster member without changing Route IDs, domains, or public status. The target cannot be the Router or host a target of a public Route; if the source hosts a public target, the source must also be the Router (`source_serves_public_route`).

Admission checks every live public Process Route's firewall and listener (`route.upstream_firewall_mismatch`, `route.upstream_unresolved`, `route.upstream_listener_changed`) and acquires the [Route publication hold](#coordinate-publication) across the Cluster (`route.publication_held` if busy). Orbit prepares Caddy and firewall rules on the target, probes Router and Process upstreams, waits up to 300 seconds for valid public TLS certificates on every public hostname, switches private DNS, waits the [withdrawal grace period](#withdrawal-grace-period), and cleans up the source.

Lower public DNS TTLs (or use a proxy) and point public DNS at the target at the start of the move so Let's Encrypt can issue certificates within the 300-second window. Failures before private DNS switches restore the source (`source_kept: true`); failures from DNS switch onward keep the target active for retry with `--from=SOURCE` (including `source_cleanup_failed: true`).

## Change an existing Route

Route updates never modify checkout source, Nodes, or Clusters. Orbit prepares new certificates, sites, `APP_URL` / production `.env`, and probes before cutover, then updates DNS, waits the grace period, and withdraws the old site. There is no rollback: failures before cutover leave the old Route serving (`failed` replacement); failures after cutover keep the replacement active and the old Route `retiring` for an identical retry.

| Update error code | Step |
| --- | --- |
| `app-dev.certificate_request_failed`, `app-dev.certificate_sign_failed` | CSR generation or signing |
| `route.publish_failed` | Certificate install and Caddy publication |
| `route.app_url_failed` | Writing new `APP_URL` or production `.env` |
| `route.probe_failed` | TLS probe |
| `route.ingress_firewall_failed` | Opening or closing Ingress firewall |
| `private_dns.publication_failed` | Updating private DNS |
| `route.dns_grace_failed` | Waiting DNS withdrawal grace |
| `route.withdraw_failed` | Withdrawing old site |
| `route.update_failed` | Any other update failure |

### Change a TLD, Cluster state, or Cluster membership

Node/Cluster TLD changes, Cluster activation/deactivation, and Node attach/detach preflight every affected private Route across the fleet before moving any Route (refusing if any active public Route would change domain or scope with `route.reconciliation_required`). Generated Routes recompute domains from the new effective TLD via replacement Routes; explicit Routes keep their domain; custom proxies keep Node scope; analytics tracking hosts follow their Instance's Route.

### Replace a Cluster Router

Replacing a Router prepares certificates, Caddy sites, firewall rules, and private DNS on the new Router while both Routers serve simultaneously, then waits the [withdrawal grace period](#withdrawal-grace-period) before retiring the old Router. Clearing a Router while the Cluster owns Routes returns `route.reconciliation_required` or `cluster.routes_require_router`.

### Change an explicit domain

`route:update ROUTE --domain=DOMAIN` changes the domain of an explicit Route (immediately for `pending` Routes; via a `pending` replacement Route for `active` Routes, moving an entire production pool together). During preparation, staging certificates (`app-instance-<id>-hostname-change`, `route-<id>-router-hostname-change`) keep the live certificates untouched until cutover (`activating` / `retiring`), after which cleanup issues final certificates and deletes the retiring Route (producing a new Route ID). Missing source profiles on older Instances fail with `instance.source_profile_missing`.

### Change an Instance Route domain

`instance:rename --domain` currently returns HTTP 503 `operation.kind_unavailable` (even when paired with `--branch`) and changes nothing. Use `instance:rename --branch` for branch-only recording and `route:update --domain` for explicit Routes.

### Resume or refuse a change

Repeat the identical request to resume an incomplete change; sending a different domain returns `route.domain_change_conflict`. Changing a generated Route's domain via `route:update` returns `route.domain_immutable` (409). Changing an active Route's single target or clearing a Cluster Router with active Routes returns `route.reconciliation_required`. Concurrent Instance lifecycle and environment operations serialize with `env.operation_busy`.

### Stored transitions

Every Caddy build renders from stored state: creation sets the publication flag after certificates exist, domain changes track `replaces_route_id` / `replacement_step`, placement changes track `transition_node_id` / `transition_cluster_id`, Router replacements track the candidate role row, and Instance removals clear targets to serve `503 Orbit Route unavailable` before teardown.

#### Withdrawal grace period

Private DNS answers carry a 30-second TTL, so transitions wait 31 seconds after moving DNS before withdrawing the old site (releasing locks during the wait). `instance:destroy` skips the grace wait because the domain is being deleted permanently. On the [Gateway engine](/reference/gateway-engine#routedestroy), `route:destroy` measures the wait from the stored DNS receipt time: a restart during it waits only what is left, and a cancel during it withdraws nothing.

## Publish each service on its own hostname

Services such as PowerSync use their own public Process hostname (e.g. `sync.example.app`) rather than a reserved path on an app Route.

### A service owns its hostname

Dedicated hostnames keep service endpoints independent of application routes without path-prefix rewriting. Reverb remains the exception, using `/app` and `/apps` through its [public attachment](/reference/websocket-apps#add-an-app-on-an-instance).

## Remove a Route

`route:destroy` removes DNS records, waits the grace period, withdraws Caddy sites, converges development PHP-FPM pools, removes certificates and unused LAN/public firewall rules, verifies no residue remains, and deletes the Route record.

The record moves to `retiring` just before it is deleted. So an additional web-root Route of an active Instance goes, and the Instance keeps its one authoritative Route. As on Swamp, the deletion first checks again that no active Instance needs the Route. An Instance that became active during the removal keeps its primary Route. The removal then fails with `route.target_conflict`. It refuses active public app Routes and Routes in an active replacement pair (`route.reconciliation_required`), `analytics_tracking` Routes (`route.tracking_managed` 409), and missing coordinators (`route.coordinator_required` 409).

| Destroy error code | Step |
| --- | --- |
| `private_dns.publication_failed` | Remove private DNS |
| `route.dns_grace_failed` | Wait DNS grace |
| `route.withdraw_failed` | Withdraw site and remove certificates |
| `route.pool_converge_failed` | Converge PHP-FPM pools |
| `route.lan_firewall_failed` | Remove unused LAN rules |
| `route.removal_check_failed` | Verify no site, cert, or rule remains |
| `route.withdrawal_failed` | Any other removal failure |

When `route.destroy` is enabled in `ORBIT_ENGINE_KINDS`, the [Gateway engine](/reference/gateway-engine#routedestroy) runs an ordinary private Route's removal with typed executor actions. Every Node must report the actions, and each VPN Node the `dns.guarded_records` feature. On the engine, the sibling check fails as `route.removal_check_failed`. `--offline`, replacements, LAN rules, the Ingress edge and `APP_URL` writes stay on Swamp.

### Remove a replacement that never activated

`route:destroy` on a `pending` or `failed` replacement Route abandons the change, withdraws staging sites and certificates, restores `APP_URL` / `.env` and DNS for the old domain, clears `replaced_by_route_id`, and leaves the original Route active. Replacements owned by an open Instance transfer return `instance.transfer_incomplete`, and replacements that already cut over (`activating`/`active`) return `route.target_conflict`.

### Remove a Route from an unreachable Node

Pass `--offline` to skip inactive or unreachable Nodes (a VPN Node is never skipped). Orbit deletes the Route, records per-Node residue (`retained_on_nodes`), and cleans it up on the next [`node:converge`](/reference/node-provisioning#converge-the-orbit-footprint) once the Node answers.

| Resource removal | Guard |
| --- | --- |
| Project | Refused while owning Routes (`project.has_routes`). |
| Cluster | Refused while scoping Routes (`cluster.has_routes`). |
| Node | Refused while serving, targeting, or generating Routes (`node.has_routes` or `route.reconciliation_required`). |
| `app-dev` / `app-prod` role | Refused while hosting a Route target. |
| `ingress` role | Refused while the Cluster has a public Route. |
| Cluster Router | Refused while the Cluster owns any Route. |

## Coordinate publication

The Gateway serializes Route writes per Node using publication holds (`route_publication_holds` for exclusive whole-file publishers, `route_withdrawal_holds` for up to 8 concurrent shared writers, at most 4 removals). A competing Route write queues in `waiting` status (FIFO) rather than failing with 409; waiting whole-file renders that detect catalog drift when their turn arrives return `route.publication_stale` (409) for a fresh retry.

An engine-run `route.update` and any other Route write never queue behind each other: whichever is second is refused with `route.publication_held` when both write one Node (a serving or edge Node, a Node the old site leaves, or a VPN Node; `RouteWriteNodes`). The engine start checks in its bind transaction; any other Route write checks right after its record exists, before it holds or dispatches.

A timed-out write whose run is not proven stopped counts as writing its Nodes until it settles. An Ingress or Router move (`node.role_relocate`, `cluster.router_set`, `cluster.router_unset`) and an Instance registration (`instance.register`) learn their Nodes only at their step, so they and an engine-run update refuse each other fleet-wide.

On each Node, shared withdrawals and container clone site publications acquire `/etc/caddy/.orbit-publish.lock` and `/run/lock/orbit-dnsmasq.lock` (30-second wait; `caddy.publish_busy` / `dns.publish_busy`), validate candidates (`caddy validate`, `php-fpm -t`, `dnsmasq --test`), back up previous files to `/var/lib/orbit/route-previous/`, verify no foreign block changed (`route.withdraw_foreign_change` / `route.publish_foreign_change` / `route.live_changed`), and reload services atomically.

Cluster Router changes serialize per Cluster with a 30-second wait (`cluster.router_busy`), and projection builds serialize on `$ORBIT_HOME/.dnsmasq-projections.lock` (30-second wait). Operations that need multiple locks acquire them in fixed order (Node lifecycle, `app-dev` source, Cluster Router, Metrics, projection, remote host) without holding DB transactions during remote work.

### Publish a development site

During container clone creation, if a shared hold is available and publication is Caddy-only, Orbit merges only the Route's `app-dev` site blocks under `/etc/caddy/.orbit-publish.lock` and activates the Route immediately upon completion of the `clone-route` step. If the Node is busy or a merge guard refuses, creation succeeds with `route_publication: deferred` and the completion Operation publishes the Route normally.

## Check Routes with Doctor

Route Operations own site, certificate, DNS, firewall, and forwarding validation. [Doctor](/cli/doctor/doctor#what-each-family-checks) does not repeat those checks or read Route Nodes; it checks role and Process liveness only.

## Why it works this way

These design choices explain the rules above.

### Routes own domains, and Cluster membership owns scope

A Route can have zero targets or span multiple production Nodes, so the domain belongs to the Route rather than an Instance. Scope follows active Cluster membership so TLD-less Clusters can still route explicit production domains.

### Domains and hostnames

Routes own application `domain` values while machines own `hostname` values. Keeping the terms separate avoids ambiguity during placement changes.

### One own Route per active Instance

Every web-serving Instance needs one canonical URL for Laravel's `APP_URL`. Extra `web_root` Routes serve separate directories in the same checkout without competing for the primary URL; the oldest Route wins `APP_URL` per directory.

### Active Cluster TLD first

Cluster members generate domains in the Cluster namespace so generated names reflect the Cluster routing them. Explicit domains stay fixed across TLD changes.

### Generated identity follows the target

A generated Route tracks its target's Node TLD (retaining `generation_basis_node_id` when targetless) rather than permanently binding to its creation Node, and stays single-target.

### Domain changes create a replacement Route

Immutable domains per Route make cutover authority and recovery explicit: the old Route stays authoritative before cutover and the replacement after.

### Separate Ingress, Router, and workload

Separating public TLS (`ingress`), backend selection (`router`), and app serving (`app-prod`) keeps public DNS and certificates stable across workload moves and pool changes.

### Round-robin without affinity or replay

Round-robin distributes load without hiding app bugs or replaying side-effecting requests; shared sessions belong in a shared session store.

### An empty Route is deleted with its last Instance

Removing an Instance releases its domain immediately unless other targets remain in a shared production pool.

### LAN first, without fallback

Configured LAN addresses express operator intent; failing visibly instead of silently falling back to WireGuard catches misconfigured LAN paths early.

### WireGuard membership is the private trust boundary

Private traffic trusts active WireGuard membership rather than maintaining per-port ACLs, while access grants authorize Orbit API commands.

### One publication field and Let's Encrypt on public Ingress

Public sites require Let's Encrypt certificates trusted by browsers, with no silent fallback to Orbit CA. Storing `publication: public` even before an Ingress exists preserves operator intent.

### Development servers on the Route origin

Proxying `/__orbit/vite` over port 443 on the Route's domain keeps Cluster routing, HMR, and TLS certificates on one origin.

### Custom proxy Routes for Node services

Custom proxy Routes give standalone Node services unique domains, private DNS, TLS, and lifecycle guards without synthetic Instances or unmanaged Caddy files.

### Public Process Routes select an edge separately from the workload

Choosing an Ingress independently lets standalone service Nodes expose public Processes without joining a Cluster or altering their private Caddy sites.

### Route child contracts

Route Operations capture target placements, detached Instance snapshots, and `orbit-control-inputs` at admission, hold Instance environment locks until completion, and trigger `metrics.reconcile` only when the public active hostname catalog changes. `route:target:unset` is record-only and broadcasts `route.updated` only after commit.

### Direct dispatch

`route.caddy_converge` dispatches directly from the Gateway without polled discovery or execution relays; complete Node Caddy builds validate and swap atomically and record their content version from the native result.
