---
title: "Routes"
description: "What a Route records, how Orbit projects its private traffic path through a Node or Router, and which later changes it coordinates or refuses."
covers:
  - apps/gateway/app/{Domain,Actions,Infrastructure,Data}/Routes/**
  - apps/gateway/app/Http/{Controllers/Api/RoutesController.php,Requests/Routes/**}
  - apps/gateway/{app/Models/{Route,RouteTarget,RouteCustomProxy}.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php}
  - apps/gateway/app/Infrastructure/AppDev/{DevelopmentCaddyConfigRenderer,DevelopmentSiteRepository,NativeDevelopmentProjectionOperationLock,RemoteAppDevCertificateManager,RemoteAppDevRouteFirewallManager}.php
  - apps/gateway/app/{Domain/AppDev/{DevelopmentServerEndpoint,PrivateDnsAnswerExpiry}.php,Infrastructure/Clusters/NativeClusterRouterOperationLock.php}
  - apps/gateway/app/Infrastructure/Instances/{NativeProductionRouteProjector,NativeDevelopmentRouteProjector,NativeDevelopmentSourceAccess}.php
  - apps/gateway/app/Domain/Instances/DevelopmentSourceAccess.php
  - apps/gateway/database/migrations/*_instance_path_proxies_table.php
---

# Routes

A Route is a domain that reaches an Instance or a Node service. This page explains the Route record, domain selection, private and public traffic, and the changes that Orbit coordinates or refuses. [`route`](/cli/route) lists the commands.

The Gateway validates Route changes and records intent as an Operation. Swamp executes the Route lifecycle through the shared Operation executor primitives. Each Node effect is a child Operation bound to its own Node. Successful completion requires the recorded child receipts. The Gateway retains Route state, input validation, ownership checks, certificate signing, and read APIs.

A Route has one of three kinds. The kind never changes.

| Kind | Owner | Serves |
| --- | --- | --- |
| `app` | A Project | One Instance, or an ordered pool of production Instances. Orbit creates one for every Instance of a web-serving Project. |
| `custom_proxy` | A Node or Cluster | A service on that Node's loopback or a Node Process. See [custom proxy Routes](#custom-proxy-routes). |
| `analytics_tracking` | One Instance | Plausible's script and event paths. See [Analytics](/reference/analytics#publish-a-tracking-host). |

Each active Instance of a web-serving Project has exactly one Route without a web root: the Instance's own Route. An Instance can be without it only while Orbit creates it, after a failed activation, or while Orbit removes it. A development Instance can also have Routes with a web root, which [serve other directories](#serve-several-web-roots) of its checkout.

A visitable development `default` serves its web root from its checkout, which each [deployment](/reference/deployments#development-defaults) updates in place. Defaults without a Route are also kept current.

## Route record

The Gateway stores these fields for each Route. `route:show` returns them.

| Field | Meaning |
| --- | --- |
| `kind` | `app`, `custom_proxy`, or `analytics_tracking`. |
| `project_id` | The Project that owns an `app` Route and every one of its targets. Null for the other kinds. |
| `node_id` or `cluster_id` | Exactly one Node or Cluster. App Cluster scope requires an active Cluster. Private custom proxies use Node scope; public Process Routes derive Cluster scope from their Ingress. |
| `domain` | One normalized domain that no other Route owns. It never changes. A domain change creates a replacement Route. |
| `web_root` | Null, or a repository-relative web root such as `apps/docs/public`. Null serves the Instance's effective root. See [Serve several web roots](#serve-several-web-roots). |
| `provenance` | `generated` or `explicit`. It never changes, and Orbit does not infer it from the domain. |
| `generation_basis_node_id` | For a generated Route, the Node whose TLD the domain uses: the current target's Node, or the last one after the target was cleared. |
| `publication` | `private` or `public`. The Route keeps it even when it has no target. Public-edge readiness shows on `status`, `replacement_step`, `failed_step`, and Doctor. |
| `status` | `pending`, `active`, `activating`, `retiring`, or `failed`. |
| `failed_step`, `error_code` | The step to retry and the code that stopped it. |
| `replaces_route_id`, `replaced_by_route_id`, `replacement_step` | A replacement pair during a domain or placement change. |
| `target_set_step` | The recorded step of a production target-set change. |
| `targets` | The ordered Instance targets. Only an explicit production Route can hold more than one. `target` repeats the first one. |
| `analytics_instance_id` | The Instance of an `analytics_tracking` Route. Null for the other kinds. |
| `upstream`, `process_id` | The loopback URL or Node Process of a custom proxy Route. |

Route responses show target IDs and positions. They never show infrastructure addresses.

### Check Route status with Doctor

Every Route is expected to be `active`. Doctor reports any other status as `route.lifecycle_not_active` in the `route` family. So a Route that an operation left `pending`, `activating`, `retiring`, or `failed` stays visible until a retry or cleanup finishes it. Doctor reads only the stored status, so the check also runs when the Node is unreachable.

No Route may hold a reserved name, such as `gateway.orbit`. A Route that took a name before core or an extension reserved it keeps the name until you move the Route. Doctor reports such a Route as `route.domain_reserved` in the `route` family. It also reads only stored state.

Doctor reports each Route on one Node. A Node-scoped Route belongs to its Node. A Cluster-scoped app Route belongs to the Node that holds the Cluster's Router role. A public Process Route belongs to its Ingress and is checked only when both the Ingress and Process Node are selected and accessible. A Route with no serving Node is not reported.

## Select a domain and scope

Supply a domain when you create an Instance to get an explicit Route. Otherwise Orbit generates the domain from the effective top-level domain (TLD):

1. The TLD of the Node's active Cluster, when that Cluster has one.
2. The Node TLD.

When neither exists, creation stops before any source or runtime change. An `app-dev` Node must have a Node TLD or belong to an active Cluster with a TLD. An `app-prod` Node can have no TLD, because production creation supplies an explicit domain.

| Instance name | Generated domain with effective TLD `test` |
| --- | --- |
| `default` | `<project>.test` |
| Any other name | `<instance>.<project>.test` |

The source branch does not change the generated domain. `instance:create <project> <node> default --branch=release` still generates `<project>.test`.

Cluster membership decides routing scope, independently of the domain. A Node in an active Cluster uses Cluster scope, also when the Cluster has no TLD and the domain uses the Node TLD. Every other Node uses Node scope. A Cluster that owns app Routes needs exactly one active Router. Public Process Routes select their edge independently, as [public Process hostnames](#public-process-hostnames) describes.

A Node keeps its own TLD while it belongs to a Cluster. [cluster](/cli/cluster#placement-and-tlds) lists which Node or Cluster may own a TLD.

### Generated domains after a Project slug update

A Project slug update recomputes every generated development Route domain from the new slug, the Instance name, and the effective TLD. Orbit creates a replacement Route for each domain that changes. The replacement keeps the Project, scope, provenance, publication, and target. Explicit domains never change. A default-branch update changes no Route. [Projects](/reference/projects#update-a-project) owns the update lifecycle.

## Create and change targets

Create an explicit app Route for an Instance with `route:create <instance> <domain> [--publication=private|public] [--web-root=PATH]`. The Instance ID determines the owning Project and the Node or active Cluster scope. Publication defaults to `private`. The Route targets that Instance and becomes active; an identical retry for an existing active Route returns that Route.

Creation keeps the Route `activating` until workload projection and any public edge activation finish. If either step fails, an identical retry resumes convergence, including rebuilding the public Ingress when its handler-build checkpoint may have been written before a crash. It does not adopt an older pending Route created by Instance provisioning. This form does not accept a Project argument, `--target`, `--node`, or `--cluster`. The custom proxy form is `route:create <domain> --node=NODE --upstream=URL` or `route:create <domain> --node=NODE --process=PROCESS`. Public Process Routes use `--process=PROCESS [--ingress=NODE] --publication=public`.

```bash
orbit route:create 12 shop.example.test
orbit route:create 12 shop.example.com --publication=public
```

The Gateway API accepts `POST /api/v1/routes` with an app Route body such as `{"instance_id":12,"domain":"shop.example.test","publication":"private"}`, and an optional `web_root`. The Instance ID implies the Project and scope; the app Route request does not take `project_id`, `node_id`, or `cluster_id`. For a custom proxy Route, the request instead supplies `domain`, `node_id`, and exactly one of `upstream` or `process_id`. A public custom proxy instead supplies `domain`, `process_id`, `publication: public`, and optional `ingress_node_id`, without `node_id`, `cluster_id`, or `upstream`. The Gateway derives routing scope from the selected Ingress; the Process keeps its own Node and Cluster membership.

| Creation refusal | Meaning |
| --- | --- |
| `route.domain_invalid` | The domain is not a valid domain. |
| `route.domain_conflict` | Another Route owns the domain, or it is a reserved name: `gateway.orbit`, `metrics.orbit`, `reverb.orbit`, `analytics.orbit`, or `collector.ai-router.orbit`. |
| `route.retry_conflict` | A Route with this domain exists with a different Instance, publication, or custom proxy configuration. |
| `route.scope_required` | A private custom proxy needs a Node; a public custom proxy needs a Process and optional Ingress. |
| `route.target_inactive` | The Instance is not active. |
| `route.target_web_root_unsupported` | The Instance has no supported relative web root, such as a package rooted at `.`. |
| `route.target_conflict` | The target Instance already has a Route without a web root. |
| `route.web_root_release_missing` | A `web_root` names a production Instance that has no selected release. |
| `route.web_root_unsafe` | A production web root would serve an application `.env`, or sits in the default directory without being the Instance root. |
| `app-prod.web_root_invalid` | The selected production release lacks the web root or its application directory, or the web root holds a link. |
| `route.router_required` | The Cluster has no active Router. |
| `route.node_inactive`, `route.cluster_inactive` | The Instance's Node or Cluster, or the custom proxy's Node, is not active. |
| `route.upstream_invalid` | The upstream is not a loopback HTTP URL. |
| `route.upstream_unresolved` | The Process has no single supported listener for this publication. |
| `route.process_conflict` | The Process is not a Node Process on the serving Node. |
| `process.runtime_lock_failed` | Process Route creation could not acquire the Process runtime lock within its bounded wait. No Route was reserved. |

A Route target must have a supported relative web root. An Instance rooted at `.`, such as a package, returns `route.target_web_root_unsupported` until an operator sets a web-root override.

| Change | Result |
| --- | --- |
| Set the current target again | The unchanged Route. On a Route that is not `active`, the Gateway grants the target's source access again. |
| Clear an empty Route | The unchanged Route. |
| Set an Instance that belongs to another Route | `route.target_conflict`. Both associations stay. |
| Replace or clear a target, or remove the Route, when that detaches an active Instance | `route.target_conflict`. Nothing changes. |
| Clear the target of a replacement Route that never activated | `route.target_conflict`. Remove the replacement with `route:destroy` instead. |
| Change the target of an `active` Route | `route.reconciliation_required`. |
| Set an Instance of another Project, or an inactive Instance | `route.target_app_conflict` or `route.target_inactive`. |
| Set a generated target without an effective TLD | `route.tld_required`. |
| Change a Route of another kind | `route.kind_unsupported`. |
| Set a target while another operation holds the projection lock | `app-dev.projection_busy`. Nothing changes. |
| Set a development target when the source access walk fails | `app-dev.source_access_failed` at step `source-access`. The target stays set, and the Gateway still broadcasts [`route.updated`](/reference/events). |

Setting a target on a generated Route moves its generation basis, scope, and domain with the target. When the domain changes, a replacement Route takes the target.

Setting a development target on a Route whose sites are published gives Caddy access to the target's web root, as in [Node scope](#node-scope). The walk covers only the target's checkout and checkouts nested in it. A Route whose sites are not published gets the access when it converges.

The Gateway takes the [projection lock](#coordinate-publication) before it reads the Route, so `app-dev.projection_busy` returns before anything changes. The access walk runs after the target is stored. When the walk fails, the new target stays set, and the error message names the Route. Set the same target on that Route again. The retry walks the checkout again and repairs the access.

### Change a production target set

An operator sends the complete ordered Instance set for one explicit, Cluster-scoped production Route. Every target must be an active production Instance of the Route's Project, on a distinct active `app-prod` Node in the same Cluster. The Cluster needs no TLD. The request can take an Instance from another Route in that Cluster.

A change that detaches an Instance must name it in `dispositions`, also when the Instance is not active. Each disposition either reassigns the Instance to a compatible explicit Route or authorizes its removal. A `pending` destination Route becomes `active` when it receives a reassigned Instance. No request removes an Instance unless `remove` is true for it. The Gateway refuses caller-supplied backend URLs, Node addresses, Caddy directives, and balancing fields.

The Gateway prepares each added target's runtime, certificate, Caddy site, and Laravel URL before the target joins the Router pool. Then it commits the association set, synchronizes `APP_URL` for every kept and reassigned Instance, publishes the destination Router pool, and republishes each vacated Route. It runs authorized Instance removals last. An identical completed request changes nothing.

The Gateway records the requested set before it starts. A failure before the association commit restores the original associations and rolls back the prepared projections. A failure after the commit keeps `target_set_step` and `failed_step`. In both cases the recorded set stays until the change completes, so the same request resumes and a different request returns `route.target_set_conflict`. After an authorized removal starts, a retry completes it and never recreates the Instance.

| Error code | Meaning |
| --- | --- |
| `route.pool_unsupported` | The Route or a target cannot own or join a production pool, such as a generated Route or an `app-dev` target. |
| `route.target_conflict` | The set holds a duplicate Instance or two targets on one Node. |
| `route.target_app_conflict` | A target or reassignment destination belongs to another Project. |
| `route.target_scope_conflict` | A target or destination is outside the Route's Cluster or not on an active `app-prod` Node. |
| `route.target_inactive` | A target Instance is missing or not active. |
| `route.target_web_root_unsupported` | A target Instance has no supported relative web root. |
| `route.target_disposition_required` | A detached active Instance has no disposition. |
| `route.target_disposition_invalid` | A disposition names an invalid destination or both reassigns and removes. |
| `route.target_set_conflict` | Another target-set change is recorded on the Route. |
| `route.web_root_unsupported` | The Route has a web root. It keeps one target. |

### Serve a production pool

Router Caddy publishes one site for the Route domain and spreads requests over the targets with round-robin. It never replays a failed request on another target. A connection or TLS failure excludes that target for 10 seconds, and then the target is eligible again. An application HTTP error, such as a 500, does not exclude a target or change Instance state.

An empty pool answers HTTP 503 with `Orbit Route unavailable`. A failed connection to a target, which Caddy reports as 502, gets the same 503 answer. When every target is excluded, Caddy answers 503 with an empty body. No answer shows a backend address. A new request never goes to a removed target once the new pool is published. A request in progress does not delay target or Instance removal.

Orbit does not change session, cookie, or encryption settings when it builds a pool. Shared sessions need the application to use one shared session store and compatible cookie settings. Round-robin does not pin a client to one target.

## Serve several web roots

A Project has one repository. An Instance is one checkout of it, and it can serve several sites: one Route for each web root. A Route's `web_root` names a directory relative to the repository, such as `apps/docs/public`. Null serves the Instance's effective root, as every Route did before.

```bash
orbit route:create 12 docs.shop.test --web-root=apps/docs/public
orbit route:update 14 --web-root=apps/admin/public
```

The API takes `web_root` on `POST /api/v1/routes` and `PATCH /api/v1/routes/{route}`; `null` clears it. A web root follows the [Project root](/reference/projects#fields) rules: a normalized relative path, without `.` or `..` segments, and not absolute. A bad value fails with HTTP 422 `validation.failed` on `web_root`. Serving refuses a web root that is missing or holds a symlink, with `app-dev.source_access_failed` in development, as in [Node scope](#node-scope), and `app-prod.web_root_invalid` in production.

| Rule | Result |
| --- | --- |
| Instance's own Route | Each Instance has at most one Route without a web root. A `laravel-app` or `symfony-app` Instance keeps it: a web root on it returns `route.web_root_conflict`. |
| Site | Workload Caddy serves the Route's domain from its web root. The Route has its own leaf, `route-<id>`, so the Instance's leaf keeps naming its own Route. |
| PHP-FPM | One pool for each application directory. Routes that serve one directory share its pool. |
| `APP_URL` | The Instance's own Route keeps its directory. Another directory takes the domain of the oldest Route that serves it. A development `default` with releases copies each directory's `.env` into its next [release](/reference/deployments#development-defaults). |
| Change | Creating, updating, or removing such a Route converges its site, pool, and `APP_URL`. A Project root change moves `APP_URL` to the new winner. |
| Instance removal | Once every refusal check passes, removes the Instance's Routes with a web root, then the Instance. A refused removal keeps them. |
| Transfer | The Routes move with the Instance and keep their IDs and domains. A public one cannot change Cluster. See [Instance transfer](/reference/instance-transfer#routes-with-a-web-root). |
| Hibernation | A request to any Route of the Instance wakes it. Dependency pruning covers only the default directory. |
| Processes and Schedules | Unchanged. They keep the default application directory or their explicit working directory. |
| Production | Served from the selected release. See [Web roots on production](#web-roots-on-production). |

The default directory keeps its pool, `orbit-app-instance-<id>`. Another directory gets `orbit-app-instance-<id>-<suffix>`, where the suffix is a stable hash of its relative path. When the last Route of a directory leaves, its pool retires and its `.env` stays. Orbit writes `APP_URL` only into a directory that holds `artisan`, and a new `.env` there gets its own key.

A Route with a web root keeps its domain, so a domain change returns `route.web_root_domain_immutable`. Send `web_root` on its own; combined with another field it returns `route.web_root_update_separate`.

Once the Instance has a PHP runtime, Doctor checks `APP_URL` in each directory that a Route with a web root serves and that holds `artisan`. It compares the value with the domain of the Route that wins the directory, by the rule above. A difference gives `instance.laravel_url_mismatch`, and its summary names the directory. See [Check Routes with Doctor](#check-routes-with-doctor).

### Web roots on production

A production Instance serves each web root from its selected release, `<home>/current/<web root>`. Deploy the Instance first. Before Orbit stores the Route, it checks the selected release: without `current`, creation fails with `route.web_root_release_missing`; a missing web root or application directory, or a link in the web root, fails with `app-prod.web_root_invalid`. Creation and a web-root change wait for a deployment or rollback of the Instance.

| Part | Production behavior |
| --- | --- |
| PHP-FPM | One more pool under the Instance's dedicated master: `orbit-<production-user>-<suffix>`, with socket `/run/php/<production-user>.<suffix>.sock`. See [PHP runtimes](/reference/php-runtime#production-runtime). |
| `.env` | A stable file, `<home>/env/<directory>/.env`. Each release links its `<directory>/.env` to that file. Orbit writes `APP_URL` there, and a new file gets its own key. |
| Create or change | Orbit links the `.env` and grants Caddy access in the selected release before the pool starts. |
| Deploy and roll back | A new release gets links before deploy steps, or fails preparation without the directory. Activation checks each web root, links, and grants Caddy access before the switch. See [releases](/reference/deployments#the-production-home). |
| Failed creation | The Route becomes `failed`, and Orbit withdraws its pool and site. Deployments, pools, and Doctor count only active Routes. Remove it with `route:destroy`. |
| Own Route | The only Route without a web root keeps it. A web root on it returns `route.web_root_conflict`, also for a `monorepo` Instance. |
| Removal | The pool leaves with the Route. `<home>/env/` stays. Instance removal first removes these Routes, as in development. |
| Target set | The Route keeps one target. A target-set change, or a move to a production Instance, returns `route.web_root_unsupported`. |

A web root must not hold a link, the same rule as for the Instance root. So no web root of the Instance, its own root included, may contain the `.env` of a served directory. A web root that is its own application directory, such as `apps/docs`, or one inside the Instance root, such as `public/docs/public`, returns `route.web_root_unsafe`. A web root in the default directory must be the Instance root itself; `apps/site` on root `apps/site/public` returns `route.web_root_unsafe`. Doctor's `APP_URL` check of each directory skips production. A cached configuration in a release keeps its old `APP_URL` until the next deployment rebuilds it.

Follow-up: a transfer that moves a public Route with a web root to another Cluster.

## Custom proxy Routes

A custom proxy Route publishes an exact domain for a service that already runs on one managed Node. It creates no Project, PHP handler, or document root.

```bash
orbit route:create executor.orbit --node=beast --upstream=http://127.0.0.1:4788
orbit route:create executor.orbit --node=beast --process=executor
```

The first form stores a loopback URL. The second form stores a Node Process and resolves its listener to a loopback or Node-local address.

| Rule | Result |
| --- | --- |
| Domain | Any unique DNS domain, such as `executor.orbit`, `grafana.internal`, or `something.test`. No Cluster TLD, Node TLD, or `.orbit` suffix is required. |
| Uniqueness | Fleet-wide, across every Route kind and the reserved platform names. Core reserves its own names, and an [extension](/reference/extensions#plug-in-points) can reserve more, such as `collector.ai-router.orbit`. `cli-proxy-api.orbit` is not reserved. |
| Owner and scope | Private: the serving Node. Public: the selected active Ingress, with scope derived from its Cluster. The Process Node can be outside that Cluster and needs no role. |
| Publication | Private with Node scope; public with a Process ID and optional Ingress selection. |
| Upstream | Private: HTTP on `127.0.0.1`, `localhost`, or `::1`, or the listener of a Node Process on the serving Node. A remote URL is refused. |
| Caddy | Private: the serving Node's site terminates Orbit CA TLS and proxies HTTP to the upstream. It keeps `Host` and admits streaming and WebSocket upgrades. |
| DNS | Private: an exact private record answers with the serving Node. The Cluster Router is not a hop. The record wins over a Cluster TLD answer for the same name. |
| Create | Private: stores the Route, issues the Orbit CA leaf, builds Caddy, then publishes DNS. Success returns an `active` Route. An identical retry returns the stored Route and converges nothing. |
| Destroy | Withdraws the site and DNS record. Private Routes remove the leaf; public Routes reconcile the Ingress firewall. Destroy again to retry a failure. |
| Node removal | Refused while the Node serves a custom proxy Route, with `node.has_routes` or `route.reconciliation_required`. |
| Process update | Allowed when the resulting specification keeps every targeting Route's single resolved TCP listener at the same address and host port. Otherwise refused with `process.route_upstream_changed` (409), naming the Route. |
| Process removal | Refused while a custom proxy Route targets the Process, with `process.has_routes`. |

To recover a `failed` private custom proxy create, destroy the Route and create it again. Repeat the identical public Process creation request to resume activation. Destroy withdraws its public site and reconciles the Ingress firewall; repeat destroy after a failure.

### Public Process hostnames

Give a Node Process its own public hostname through a selected Cluster Ingress.

```bash
orbit route:create sync.example.com --process=267 --publication=public
```

The API body is `{"domain":"sync.example.com","process_id":267,"publication":"public"}`. Add `ingress_node_id` or CLI `--ingress=<node>` to select an active Ingress by ID (the CLI also accepts an exact Node name). By default, Orbit selects the public Ingress of the Process Node's Cluster. For a Process Node outside a Cluster, Orbit selects the single active Ingress serving existing active public app Routes. No candidate returns `route.ingress_required`; multiple candidates return `route.ingress_ambiguous`. An explicit Ingress must be active with an active `ingress` role.

The stored Route has that Ingress's Cluster scope. Orbit never changes the Process Node's Cluster membership or Caddy sites. The caller needs command access to the selected Ingress and the Process Node; unrelated Cluster members need no grant.

The Process must be an active Node-owned Docker Process on an active Linux Node. When both Nodes have recorded IPv4 LAN addresses in the same /24, Orbit selects the Process Node's LAN address; otherwise it selects WireGuard. Cluster membership does not affect address selection.

The Process must publish exactly one TCP listener on that selected address. A missing or ambiguous listener returns `route.upstream_unresolved` before storing intent. Orbit never rewrites published ports or falls back from a missing LAN listener to WireGuard. Add the LAN publication with `process:update` first. Loopback, wildcard, and UDP listeners are ineligible. A raw `upstream` stays private and loopback-only. Public Process IDs are numeric. Custom proxy Routes cannot change domain, scope, target, or publication after creation.

The selected Ingress terminates public TLS with the same ACME policy and `ingress-firewall` activation step as public app Routes. It sends HTTP directly to the resolved Process listener, keeps Host, streams without buffering, permits WebSocket upgrades, and allows one hour between upstream reads and response headers. It needs no Router hop or Router role, and installs no Caddy site or certificate on the Process Node. Private DNS answers with the Ingress. Set public DNS yourself; a Cloudflare proxy can front the public hostname.

Activation verifies the Process Node's firewall and sends an HTTP HEAD request from the Ingress to the selected listener before publishing. WireGuard uses the existing `orbit:wireguard-members` trust rule. LAN needs an active managed `orbit:` TCP allow rule for the Ingress LAN source and selected port; a rule for another port, source, or network does not qualify. Orbit refuses activation with `route.upstream_firewall_mismatch` when this rule is missing, and `route.upstream_unreachable` when the listener cannot be reached.

The Gateway sends the selected address, port, and Route domain to `route.verify-http`. The agent sends HEAD with the Route's Host header, bypasses HTTP proxies, and waits at most ten seconds. Any HTTP status proves reachability; it does not follow redirects or require application success.

It keeps the activation for retry and Doctor reports the blocked path. Orbit adds no hidden firewall opening or Process port rewrite. Use the managed [firewall commands](/cli/firewall) to allow the selected LAN source before retrying. Docker publications bypass UFW, as that page explains; this check requires recorded firewall intent and does not make UFW an isolation boundary for Docker.

Node convergence restores the WireGuard member rule; Ingress convergence rebuilds the public site and HTTP rules. Removing the last public Route closes the public Ingress rules, including after offline residue cleanup. Process removal stays refused with `process.has_routes`.

Configuration and image updates are allowed when they preserve the Route's resolved upstream; see [Process updates](/reference/processes-and-schedules#update-in-place). The Route keeps its ID, active state, site, certificate, DNS, and firewall rules. A container replacement can briefly interrupt requests while the listener restarts. Caddy reconnects to the same upstream; no Route activation or rebuild is needed. Doctor's existing upstream check reports an unreachable listener after an update, for both public and private Process Routes.

Process Route creation shares the [Process runtime lock](/reference/processes-and-schedules#locks) with updates. If an update is running, creation waits, reads the current Process, and resolves its listener again before reserving the Route. If creation reserves the Route first, an update must preserve that Route's listener.

Doctor checks public Process Routes on the Ingress in the `route` family only when both Nodes are in its selected scope. Otherwise it reports nothing about that Route and does not inspect the Process Node. It checks lifecycle, reserved names, private DNS, rendered site, public certificate, forwarding from the Ingress, and both firewall ends. A missing or expiring public certificate is a certificate mismatch. An unavailable observation is an inspection failure.

The DNS check queries the recorded Orbit DNS server directly from the Gateway and expects the Ingress's WireGuard address. It bypasses system resolver routing and caches. The Ingress's system resolver may return a public address for the same hostname; that is not private DNS drift.

The first [Node Caddy build](/reference/caddy-configuration#replaced-configuration) on a Node backs up and stops serving any hand-placed Caddy site. Create a custom proxy Route for such a site before that build. [Migrate an unmanaged Executor hostname](/solutions/migrate-unmanaged-executor-hostname) shows an example.

Doctor checks each custom proxy Route on its serving Node in the `route` family:

| Doctor issue code | Difference |
| --- | --- |
| `route.dns_mismatch` | Private DNS does not answer the domain with the serving Node address. |
| `route.certificate_mismatch` | The private Route's Orbit CA leaf is missing, or the public Route's ACME certificate is missing or expiring. |
| `route.caddy_mismatch` | The serving Node's Caddy does not contain the site. |
| `route.upstream_unreachable` | The upstream does not accept a connection. |
| `route.firewall_mismatch` | The public Ingress rules or the managed Process listener path do not match. |
| `route.inspection_failed` | A required observation is missing, malformed, or unreachable. |
| `route.node_unreachable` | The Gateway could not observe the serving Node. |

## Set up private traffic

The Gateway prepares the runtime, certificates, Caddy sites, and firewall rules of a new Route before it publishes private DNS. It publishes DNS last and then marks the Route and Instance active. It does not wait for the application to answer successfully.

### Node scope

Private DNS points the domain at the workload Node. Its Caddy terminates HTTPS with an Orbit certificate authority (CA) certificate and serves the Instance's web root.

Before the Gateway publishes a development Route, it gives Caddy read access to the web root and traversal access to its parent directories. Caddy cannot read the other source files. The web root must be inside the checkout. Symlinks in the web root are refused, except Laravel's `public/storage` link to the checkout's `storage/app/public`. When this preparation fails, the Gateway restores the previous permissions and reports `app-dev.source_access_failed` at step `source-access`.

This preparation walks only the Instance's checkout and served checkouts nested in it. Other checkouts on the Node keep the access their own Route granted.

#### Caddy access

The agent publishes development TLS leaves under `/etc/caddy/orbit-certificates/<scope>`. It creates the shared `orbit-certificates` directory on first publication. That directory, the scope directory, its `versions` directory, and each certificate version belong to `root:caddy` with mode `0750`. Caddy can traverse the complete path to the certificate and its group-readable private key.

Caddy runs as its own user and must reach each development site's web root, also when the [apps root](/reference/node-settings#derive-the-effective-root) lies outside the managed user's home. The same preparation runs for a [task VM](/reference/compute-drivers#task-vms) workspace that has a Route. The Node agent's `instance.grant-development-caddy-access` runs it. Its ACL tools run as the managed user, in this order:

1. Deny Caddy the whole checkout and its Git directory, including the default ACL of every directory, so new files stay closed too.
2. Grant Caddy read access to the web root and to the Laravel public storage target.
3. Grant Caddy execute access on each directory above the web root that it cannot enter yet. Caddy can pass through those directories but cannot list them.

Every deny comes before any grant, so a served checkout nested in another keeps its own web root. No change follows a symlink. The managed user changes its own entries. An entry that the managed user does not own fails the preparation with "Operation not permitted"; the walk never skips it. The agent acts as root only on a directory above every checkout that root owns, on a path that root owns from `/` down, such as an apps root's parent. Root never changes anything at or below a checkout.

The grants keep each entry's mask. A file or directory whose mode hides it from the group class, such as a mode 0600 environment file, stays hidden from Caddy even when it lies in the web root. Only the passage grant on a directory adds execute to its mask. When Caddy still cannot read the web root or the public storage target, the preparation fails with `instance.caddy_access_failed` and names it. When Caddy cannot read some entries in them, the preparation succeeds, and the agent's result lists up to 100 of those entries.

Setup can run while the walk runs, for a task VM workspace whose Route follows its baseline start. An entry that vanishes during the walk needs no change, and the walk does not fail for it. A new entry inherits the default ACL of its directory.

Before it changes access, the walk saves the ACLs of what it grants: the web root, the public storage target, the directories above them, and the Git directory. The saved ACLs live in a private directory under `/var/tmp`, which is on disk, not in a `/tmp` that can be a memory-backed tmpfs. Only the managed user can enter that directory. Root-owned directories go in a separate file that only root restores.

When the preparation fails, the walk restores the saved ACLs, and the deny on the rest of the checkout stays. So a failure never widens access. When the restore fails, the saved directory stays, and the error `instance.caddy_access_recovery_failed` names it in `snapshots`. The next walk removes the managed user's saved directories that are older than three days.

### Cluster scope

Private DNS points the Route domain and the Cluster TLD at the Router. [Private DNS](/reference/private-dns#cluster-router-addresses) describes which Router address each requester gets.

Router Caddy forwards Orbit CA HTTPS to the workload Node and keeps the domain as the HTTP `Host` value and the TLS server name. The Router and the workload Node get separate private keys. The Router uses the workload Node's LAN address when the Node has one, and its WireGuard address otherwise. A configured LAN address that does not answer fails publication. Orbit never falls back to WireGuard, also not per target in a pool.

The Gateway sends the Route ID and the two LAN addresses to `firewall.route-lan`. The agent owns the TCP 443 allowance and its `orbit:route-<id>-lan` comment. Repeated convergence keeps an existing matching rule. Removal deletes every numbered rule with that exact comment, highest number first, and verifies absence under the same firewall lock. An inactive firewall stays inactive; convergence checks its stored rule, and removal leaves its stored rules unchanged.

Before publication, `route.verify-certificate` checks each remote workload from its Router and each public app Route hop from its forwarding Node. It connects to the selected address on port 443, sends the Route domain as TLS SNI, and requires a chain trusted by that Node. This preserves the existing OpenSSL chain check; it does not add a separate hostname check. The Gateway keeps placement, activation order, and public error mapping. Router replacement uses the same commands.

When the Router and the workload share one Node, one Caddy service serves the Route and sends the request to the local runtime. It never proxies to its own HTTPS listener. A pool that mixes a local and a remote target serves the local target through an internal Unix listener.

### Development-server endpoint

The reserved path `/__orbit/vite` serves live frontend assets and hot module replacement (HMR) on the Route's HTTPS domain, on port 443. Workload Caddy proxies that path to the Instance's assigned `vite_port` on loopback and keeps the path prefix. When the Instance has no assigned `vite_port`, Caddy does not proxy that path. Router Caddy forwards it like any other path. HTTPS and WSS terminate with the Route's Orbit CA certificates.

Each development Instance on a Node has its own port, and separate Nodes can reuse a port. See [Assigned Vite ports](/reference/assigned-vite-ports). When nothing listens on the port, Caddy returns a proxy error for that path only, and never picks another Instance.

A development systemd Process gets these values from the Route domain:

| Variable | Value |
| --- | --- |
| `ORBIT_DEV_SERVER_ORIGIN` | `https://<route-domain>/__orbit/vite` |
| `ORBIT_DEV_SERVER_HOST` | The Route domain |
| `ORBIT_DEV_SERVER_PATH` | `/__orbit/vite` |
| `ORBIT_DEV_SERVER_PORT` | The Instance's assigned port |

A Vite server that follows this contract binds its port on loopback and publishes the Route origin:

```js
import { defineConfig } from 'vite'

const origin = process.env.ORBIT_DEV_SERVER_ORIGIN
const path = process.env.ORBIT_DEV_SERVER_PATH

export default defineConfig({
    base: path ? `${path}/` : '/',
    server: {
        host: '127.0.0.1',
        port: Number(process.env.ORBIT_DEV_SERVER_PORT),
        strictPort: true,
        origin: origin ? new URL(origin).origin : undefined,
        hmr: origin
            ? {
                protocol: 'wss',
                host: process.env.ORBIT_DEV_SERVER_HOST,
                clientPort: 443,
                path: 'hmr',
            }
            : undefined,
    },
})
```

Vite adds its base to the HMR path, so set `hmr.path` to `hmr`. The `vp-dev` preset sets `--base=/__orbit/vite/`. Laravel's `@vite` directive reads `public/hot`. That file must contain `ORBIT_DEV_SERVER_ORIGIN`, so the browser loads `/__orbit/vite/@vite/client` from the Route domain.

### Private network trust

Every active WireGuard member may reach every other active member on its WireGuard address, over every protocol and port. Each Node keeps the firewall rule `orbit:wireguard-members` for this. An access grant never limits ordinary private traffic. It authorizes only Orbit commands and Gateway API calls. `metrics.orbit` is the exception: it checks Gateway authority, as [Metrics](/reference/metrics#grafana-access) describes.

On its LAN address, a Router admits HTTPS on TCP port 443 and DNS on UDP and TCP port 53 from eligible members of its active Cluster. Each source is an active registered Node with LAN and WireGuard IPv4 addresses and a WireGuard public key, excluding the Router itself. The [Router LAN firewall contract](/cli/firewall#router-lan-member-rules) owns exact destination scope, transition ordering, Doctor checks, and rollout. An overlapping operator or unmanaged allow must be narrowed before member-only DNS isolation can be claimed. Adding firewall rules does not install Cluster DNS, switch member resolvers, or change Route answers.

Public traffic enters only through Ingress, on HTTP and HTTPS. The firewall never makes a Router or workload Node a direct public endpoint. A standalone production Route is private and terminates Orbit CA TLS on its workload Node.

## Publish a public Route

A public app Route terminates HTTPS on the Cluster's Ingress, forwards privately to the Cluster's Router, and reaches the `app-prod` workload. [Public Process Routes](#public-process-hostnames) use the same Ingress and send HTTP directly to their Process. The public endpoint never shows which Node runs the workload.

A Cluster has at most one active Ingress. The database refuses a second one, and Doctor reports `role.cluster_cardinality_conflict` if two exist. The public app edge exists only when all of these hold: the Route is Cluster-scoped, the Cluster is active, the Cluster has an active Router, and its Ingress serves. An Ingress serves while its role is active, while it converges, and after a failed convergence step. A new public activation, or its repeat on deploy, starts only while the Ingress role is active. When a condition is missing, the Route keeps `publication=public` but gets no public listener, certificate, or firewall opening.

A public edge is live when the Route is `active` or `activating`, its Cluster is eligible, and its `replacement_step` is at or past `public-activated`. A finished public Route stores `ingress-firewall`, a later step. A public Route whose `replacement_step` is empty has not finished activation and has no live public site.

The public app Ingress site names the domain and forwards to the Router. It never names an Instance, a workload Node, or a backend pool, so the Router keeps backend selection. Ingress forwards Orbit CA HTTPS to the Router's LAN address when the Router has one, and to its WireGuard address otherwise. It keeps the original `Host`, the HTTPS scheme, and the client address.

Public TLS terminates on the Ingress with a Let's Encrypt certificate that Caddy obtains through `tls force_automate`. Orbit never pins an Orbit CA leaf on a public site, and a Let's Encrypt failure never falls back to Orbit CA. [Caddy configuration](/reference/caddy-configuration#public-ingress-certificates) describes the site block. Create the public DNS record yourself. Orbit calls no DNS provider API and stores no public IP address.

When Ingress shares a Node with the Router, with `app-prod`, or with both, one Caddy service serves the public Route and never proxies to its own public listener. When the Ingress Node runs a target of the Route, its public site serves that target directly and replaces the target's private site for that host. A Router on another Node then accepts the public certificate, because the Node's system roots include the Orbit root. Until Let's Encrypt issues the certificate, that host does not complete TLS on the Node.

On an Ingress Node, only public sites bind every address. Router and workload sites stay on the WireGuard and LAN addresses, as [listener addresses](/reference/caddy-configuration#listener-addresses) describes. `ingress` and `gateway` never share a Node: `node:role:add` refuses `ingress` on the Gateway Node, and `node:role:relocate` refuses `gateway` onto an Ingress Node.

The Ingress firewall opens `orbit:ingress-http` (port 80) and `orbit:ingress-https` (port 443) only while the Cluster has at least one live public Route. When the last one leaves the public edge, the Ingress firewall step closes both rules. A new Ingress Node without a public Route has ports 80 and 443 closed to public traffic: UFW denies incoming traffic by default, and neither `orbit:ingress-http` nor `orbit:ingress-https` is present. Its only managed firewall rule is `orbit:wireguard-members`, which allows private WireGuard traffic.

### Publish the public edge

A publication-only change keeps the Route ID and the domain. To publish, the Gateway verifies the private hops, stores `public-activated`, and builds the Ingress Node, so Caddy can obtain the certificate. Then it opens the Ingress firewall and stores `ingress-firewall`. The public site stays unreachable until those steps succeed, also when another Route already keeps the Ingress ports open. A failed step returns its error to the caller. On a Route that is not `active`, it also stores `failed_step` and `error_code`.

Nothing in Orbit waits for or watches certificate issuance. Caddy requests the certificate after the build and retries on its own. Doctor reports a public Route whose Let's Encrypt certificate is missing or expires within the renewal margin, in addition to checking that the site asks Caddy to manage its certificate. Other issuance failures appear in Caddy's log on the Ingress Node and as a TLS failure for clients.

A change of both domain and publication reserves a replacement Route with the new publication. The current Route stays authoritative until cutover, as in [Change an explicit domain](#change-an-explicit-domain). An app Route can be public only when its targets are production Instances. A [public Process Route](#public-process-hostnames) has no Instance targets.

The Gateway refuses to remove an Ingress while any Route in its Cluster has `publication=public`.

### Ingress removal

`node:role:remove NODE ingress --force` is refused with reason `public_routes_attached` while any Route in the Node's Cluster has `publication=public`. Make each such Route private or remove it first. The Gateway checks this again when it claims the assignment.

The claimed assignment is `removing`, so it serves nothing. The Gateway builds the Node's Caddyfile: the public sites leave, and the Node's other sites stay on their private addresses. Then it closes `orbit:ingress-http` and `orbit:ingress-https` and reconciles service metrics. Caddy stays installed. When Ingress was the Node's last role, the Gateway reopens public SSH before it deletes the assignment.

The same command removes an Ingress whose convergence failed. A failed step leaves the assignment `failed` with `failed_step=remove:STEP` and an `error_code`. Run the same command again to retry. A failure in the build uses `ingress.caddy_config_failed`, as [When a build fails](/reference/caddy-configuration#when-a-build-fails) describes. With `--offline`, the Gateway removes an unreachable Ingress on its side only and lists what stays on the Node under `retained_on_node`.

## Change an existing Route

A Route change never changes Instance source, Nodes, Clusters, or checkouts. Changes that move a Route between placements or domains follow these rules:

- The Gateway verifies the new Caddy sites, certificates, firewall rules, and Laravel URL before it publishes the new domain or scope.
- A failure before publication restores the previous state.
- A failure after cutover recovers forward, so a Route never has two authoritative domains or scopes.
- Each failure records `failed_step` and `error_code`. A retry with the same request checks the finished steps and resumes at the first unverified step.
- A conflicting request is refused.

For a development Laravel source, the Gateway sets `APP_URL` in the environment file and the cached configuration without Composer, Artisan, or application bootstrap. For a production source, it renders the stored environment against the new Route and replaces only the home's `.env`. It runs no deployment or restart commands. A stale cache or an HTTP error does not block a valid change. See [Instance environment variables](/reference/environment-variables#synchronize-during-a-domain-change).

### Change a TLD, Cluster state, or Cluster membership

These changes reconcile every private Route that depends on the affected Node or Cluster before the change becomes authoritative: a Node TLD change, a Cluster TLD change, Cluster activation or deactivation, and a Node attach or detach. The Gateway checks every affected Route, and a Node's LAN address against the Cluster, before the first Route moves. It compares each proposed domain with every Route domain in the fleet. One invalid or occupied result refuses the whole change and leaves every Route in place.

A Node attach or detach that would change the domain or scope of an active public Route is refused with `route.reconciliation_required`.

| Route | Result |
| --- | --- |
| Generated | Takes the domain from its target name, or its generation basis when it has no target, and the new effective TLD. |
| Explicit | Keeps its domain. |
| Custom proxy | Keeps its Node scope and keeps serving. |
| Analytics tracking host | Moves with its Instance's Route. |

A domain change creates a replacement Route. A scope-only change keeps the Route ID. Old projections leave only after the new domain or scope is authoritative.

Some examples follow from the TLD rules. When a Node joins an active Cluster with a TLD, its generated domains move into the Cluster namespace, and detach falls back to the Node TLD. A Node TLD change does not rename a generated Route that uses an active Cluster TLD. Removing a Cluster TLD keeps Cluster routing and falls back to Node TLDs. The Gateway refuses that removal when a generated Route would have no effective TLD.

### Replace a Cluster Router

Replacing a Router prepares the new Router's sites, Route keys, firewall rules, and DNS answers before the new assignment becomes authoritative. Route IDs, domains, targets, scopes, workload sites, Laravel URLs, and Instance placement stay the same.

From the Router Caddy step until cleanup, both Routers serve the Cluster's Router sites, because clients can hold a DNS answer for the old Router. After the DNS publication step, private DNS answers with the new Router. The Ingress forwards to the new Router once it becomes active. Cleanup publishes private DNS, waits out the [withdrawal grace period](#withdrawal-grace-period), and then stops serving the Router sites on the old Router. It removes the old Router's certificates and firewall rules last. When the DNS publication fails, both Routers keep serving until a retry.

A failure before publication moves private DNS back to the old Router while the candidate still serves, then withdraws the candidate. When DNS cannot move back, the candidate keeps serving until a retry. Each failure records `failed_step` and `error_code` on the candidate's `router` role row.

Clearing a Router while the Cluster owns Routes returns `route.reconciliation_required` or `cluster.routes_require_router`.

### Change an explicit domain

`route:update ROUTE --domain=DOMAIN` changes the domain of an `active`, explicit Route. For a `pending` explicit Route, it creates the replacement Route at once, with no projection steps. The Route can be development or production. A shared production Route moves its whole ordered pool to one replacement. This is how a production clone swaps its preview domain for its real domain.

The Gateway reserves a `pending` replacement Route for the same Project and targets. The current Route stays the only authoritative Route. The Gateway refuses an invalid, occupied, or conflicting domain before it changes anything. It prepares the replacement's workload certificate and Caddy site, then the Router certificate, firewall rules, and Router Caddy site, then the Laravel URL or production environment. It publishes the new domain in private DNS last. When a target has no recorded source profile, the Gateway returns HTTP 409 `instance.source_profile_missing`; Orbit does not recover missing profiles on older Instances, as the [no-legacy-support rule](/reference/projects#one-public-name-without-compatibility) explains.

Cutover is one database transition: the replacement becomes `activating` and the old Route `retiring`. Instance output shows only the new domain, and Route inspection shows both records. Cleanup removes the old projections, deletes the retiring Route, releases its domain, and marks the replacement `active`. A successful change therefore produces a new Route ID.

Until cleanup, the new domain uses staging certificates, so the Instance's live certificate still names the old domain:

| Certificate scope | Node | Issued at | Removed at |
| --- | --- | --- | --- |
| `app-instance-<id>-hostname-change` | Workload | Workload certificate step | Cleanup or rollback |
| `route-<replacement id>-router-hostname-change` | Router | Router certificate step | Cleanup or rollback |
| `app-instance-<id>` | Workload | Reissued for the new domain at cleanup | Instance removal |
| `route-<replacement id>-router` | Router | Cleanup | Route removal |
| `route-<retiring id>-router` | The retiring Route's Router | Before the change | Cleanup, after that Router drops its site |

Swamp executes `certificate.converge` and `certificate.remove` on one recorded Node and certificate scope. A leaf request returns only a public CSR. The Gateway signs it, then resolves protected certificate references at publication. Completion requires the request, signing, publication and inspection receipts, or proof that the current leaf was inspected. Removal refuses a scope still used by a stored Caddy site. Lost replies recover the original sanitized target receipt without generating another key.

A `pending` replacement renders a site only after its certificate step completes. A `failed` replacement renders no site. Cleanup issues the live certificates, stores its `cleanup` step, rebuilds the workload and Router Caddy, and only then removes the staging certificates. So a failed cleanup never leaves a site that names a missing certificate.

### Change an Instance Route domain

[`instance:rename INSTANCE --domain=DOMAIN`](/cli/instance#orbit-instancerename) changes the domain of an active development checkout's own single-target Project Route. The API is `POST /api/v1/instances/{instance}/rename` with `{"domain":"login-redirect.orbit-website.test"}`. It can also record a branch already checked out by supplying `branch` in the same request. This operation does not rename the Instance, move its checkout, or change its placement.

Unlike an ordinary operator-requested `route:update`, Instance rename permits a domain change when the Route has `provenance=generated`. It uses the same replacement and convergence path as an [explicit domain change](#change-an-explicit-domain): reserve the new domain, prepare certificates and Caddy, synchronize the Laravel URL, publish DNS, cut over, and clean up the old projections. A successful rename returns the Instance with the new Route and URL; the old domain stops serving it. The replacement has a new Route ID.

The replacement keeps the Project, scope, publication, target, provenance, and generation basis. A generated Route stays generated; a later Project slug or effective-TLD change can recompute its domain from the unchanged Instance name. A supplied readable domain is not a permanent explicit override. Explicit Routes keep their existing explicit-domain behavior.

For Laravel, convergence writes the new `APP_URL` into the stored Instance environment and `.env` and refreshes cached configuration through the existing URL synchronization step. It does not just edit a Route row. A target without a recorded source profile returns `instance.source_profile_missing`.

Before changing anything, the Gateway validates the Instance, any supplied branch, and the normalized domain's availability. A branch mismatch or `route.domain_conflict` in a combined request changes neither branch record nor Route. A domain request without the Instance's own single-target Project Route returns `instance.route_required`; custom proxy and analytics Routes are not candidates. The changed branch record is committed only after the requested domain converges successfully.

An identical retry after a completed rename returns the Instance without creating an additional Route, including when the caller lost the success response and when provenance is generated. Incomplete replacements follow the recovery rules below. If a full rollback before cutover deleted the failed replacement, an identical retry can reserve a fresh replacement. A changed branch is recorded only after domain convergence succeeds. Another lifecycle owner returns `instance.lifecycle_busy`, and a different requested domain while a replacement is incomplete returns `route.domain_change_conflict`. Branch-only rename leaves the Route alone. [Development branch reconciliation](/domains/applications#development-branch-reconciliation) explains the reasons for this Instance-owned path.

### Resume or refuse a change

During a change, Route inspection shows both records, `replacement_step`, `failed_step`, and `error_code`. Repeat the same request to resume. A different domain returns `route.domain_change_conflict` and changes neither record.

A failure before cutover leaves the old Route authoritative. The Gateway marks the replacement `failed`, removes its sites and staging certificates, restores the Laravel URL or production environment, and republishes private DNS. Then it deletes the replacement, and a retry starts a new change. When that cleanup is incomplete, the `failed` replacement stays inspectable. The identical request recovers it, and it starts again from the first step. To abandon the change instead, remove the replacement with `route:destroy`, as [Remove a replacement that never activated](#remove-a-replacement-that-never-activated) describes.

A failure after cutover keeps the replacement authoritative. A retry continues until the replacement is `active` and the retiring Route is deleted.

`route.reconciliation_required` refuses these changes to an active Route: a generated domain change that an operator requests through ordinary `route:update`, and a single-target change. The Instance-owned [`instance:rename`](#change-an-instance-route-domain) path permits its generated Route's domain change, but not an incompatible reconciliation already in progress. A Router clear gets the same refusal.

Deployment, rollback, clone finalization, Instance removal, environment changes, and domain changes of the same Instance share one operation owner. A competitor waits or returns `env.operation_busy`.

### Stored transitions

Every Caddy build renders a Route's sites from stored state only. A transition stores its state before the build that needs it. A withdrawal stores its state before the build that removes a site, and before the certificate removal.

| Transition | Stored state | Sites |
| --- | --- | --- |
| Creation | The site publication flag, set once the Route's certificate exists | The `pending` Route renders like an `active` one. A failed creation clears the flag. |
| Domain change | The `pending` replacement Route and its `replacement_step` | Each replacement site appears after its certificate step, with staging certificates until cleanup. |
| Placement change | `transition_node_id` and `transition_cluster_id` on the Route | The candidate placement before cutover, and the old placement after it, until the `cleanup` step. |
| Router replacement | The candidate `router` role row and its `failed_step` | Both Routers serve the Cluster's Router sites from the Router Caddy step until cleanup. |
| Instance removal | No targets, the site publication flag, and an open development removal member | The Router, or the workload Node when there is no separate Router, answers `503 Orbit Route unavailable`. |

During a placement change, the candidate's Router sites appear after its Router certificate step and use `route-<id>-router-hostname-change`. At cutover the Route takes the candidate placement, and the transition columns take the old one. When both placements render the same site on one Node, the current site wins. Private DNS answers with the current placement, so its records move when cleanup publishes private DNS.

Cleanup issues the live Router certificate for the new placement and publishes private DNS. It waits out the grace period, stores its `cleanup` step, and builds every affected Node. It removes the staging and old certificates, and clears the transition columns last, so a retry still knows the old placement. A failure before the `cleanup` step keeps both placements serving.

A restore before the Router Caddy step clears the columns, builds, and removes the candidate certificates. A restore after that step first publishes private DNS for the current placement and waits out the grace period. When that DNS publication fails, both placements keep serving until a retry.

#### Withdrawal grace period

Private DNS answers carry a 30-second TTL. After a transition moves a name, it waits 31 seconds, the TTL plus one second, before it stops serving the old target. One Cluster change waits once for all the Routes it moves. It does not hold the projection owner while it waits, so other commands run. It then reads each Route again before it withdraws the old placement.

The wait counts from the last DNS move of that Route, which the Route stores. When another change moves the same Route during the wait, that change waits its own full period.

The grace period covers clients whose resolver honors the TTL, such as systemd-resolved and dnsmasq on Orbit Nodes. Caddy finishes every request in progress on the old target and closes idle keep-alive connections, so the next request resolves the name again. A client with a longer DNS cache of its own, such as a browser or a Java runtime, can still reach the old target and get a TLS error until its cache expires. Retry the request or reload the page.

## Publish each service on its own hostname

PowerSync uses a separate hostname for each app, such as `sync.mealou.app` or `sync.hauzer.app`. Set its client endpoint to the service hostname. App Routes do not reserve `/powersync` or strip a service path prefix.

### A service owns its hostname

A separate hostname for each service replaces ADR 0231, which published a Process under a reserved path on an app Route. A separate hostname keeps the service endpoint independent of the app's Laravel routes and removes the need for path-prefix inspection, rewriting, and publication state. Reverb still uses the app domain's reserved `/app` and `/apps` paths through its [public attachment](/reference/websocket-apps#add-an-app-on-an-instance).

## Remove a Route

`route:destroy` removes a Route and its projections. The Gateway refuses an app Route whose public edge is live, and a Route in a replacement pair, with `route.reconciliation_required`. The one exception is a [replacement that never activated](#remove-a-replacement-that-never-activated).

Removal clears the site publication flag and sets the Route `retiring`. Then it runs these steps in order: remove DNS records, withdraw the Caddy sites, converge PHP-FPM on the Node of each development target, remove the certificates, remove firewall rules, and delete the record. A failure keeps the Route `failed` with `failed_step` and `error_code`. Repeat the same command to retry. The retry runs every step again from the start, and clears the failure once the failed step passes.

Removal releases the domain at once. The Node's shared runtime and Caddy service stay.

A targeted Route can be removed only when none of its Instances is active and the Route is not `active`. It runs the same steps, so a `pending` Route that already published its sites leaves nothing live on its Nodes. A task workspace whose source resolved is an example. The Caddy and firewall steps include every target Node.

The PHP-FPM step converges each Node of a development target, right after its site leaves. It converges every pool on the Node, so a PHP-FPM problem unrelated to the Route also stops the removal; fix it and retry. A pool skipped only because its working directory is missing does not stop it, as in [Instance removal](/reference/instance-removal). A production target keeps its dedicated PHP-FPM service, and every target keeps its Instance certificate, until [Instance removal](/reference/instance-removal). The Instances stay without a Route.

A targeted removal records its failed step with the prefix `targeted:`, for example `targeted:caddy`. A retry of the same removal resumes it. A Route whose untargeted removal failed and that has gained a target since then is refused with `env.owner_changed`.

### Remove a replacement that never activated

A domain change, an Instance rename, or a Project slug update reserves a `pending` replacement Route that copies the target of the Route it replaces. Until cutover, the replaced Route stays `active` and serves the Instance. `route:destroy` on such a replacement, `pending` or `failed`, abandons the change.

It runs the same withdrawal as a failure before cutover. It marks the replacement `failed` and removes its sites and staging certificates. It restores the Laravel URL or production environment for the old domain and republishes private DNS. Then it clears `replaced_by_route_id` on the replaced Route and deletes the replacement with its target. The replaced Route and its target stay as they are. A failure keeps the replacement `failed`, with `failed_step` `withdrawal` when the change had recorded none. Repeat the command to retry.

An open Instance transfer owns its source and destination Routes. `route:destroy` returns `instance.transfer_incomplete` for either Route, including a replacement that never activated. Retry the identical transfer request to finish or roll back the transfer first.

The [offline removal](#remove-a-route-from-an-unreachable-node) rules also apply to a replacement that never activated. Skipped Nodes keep their configuration and application URL. The old Route stays authoritative. A skipped workload Node can keep its unused Instance hostname-change certificate until the next domain change or Instance removal; Route residue cleanup removes the certificates owned by Route ID.

A replacement that has cut over is `activating` or `active`. `route:destroy` refuses it like any Route that an active Instance needs, with `route.target_conflict`. Finish that change by repeating its request. `route:target:unset` on a replacement that never activated is refused with `route.target_conflict`, and the message names `route:destroy`, because a replacement without its target would replace nothing.

```bash
orbit route:destroy 199
```

### Remove a Route from an unreachable Node

The Caddy, PHP-FPM, certificate, and firewall steps change Nodes over SSH. When one of those Nodes does not answer, the step fails. The Gateway then probes the Nodes of that step and, for a Node that is not `active` or does not answer, returns `route.node_unreachable` instead of the step's error. The message names the Node and the `--offline` option, and `details` keeps the step and the original error code. The Route stays `failed` at that step.

`route:destroy --offline` checks each Node before it changes anything. It skips a Node that is not `active`, or that the [reachability probe](/reference/node-provisioning#remove-a-node) finds unreachable. A Node that answers keeps every step, and a failure on it still stops the removal. The Gateway runs the other steps, deletes the Route, and records a residue for each skipped Node in the same transaction. The response lists them under `retained_on_nodes`, each with `node_id`, `node`, and the `steps` the Node still needs.

Doctor reports each residue as `node.route_residue_retained` in the `node` family, also while the Node is down. The `route-residue` artifact of [`node:converge`](/reference/node-provisioning#converge-the-orbit-footprint) finishes the removal once the Node answers. It builds Caddy and converges PHP-FPM from stored state, which has no record of the removed Route. Then it removes the Route's certificates and firewall rules by Route ID, reconciles public firewall policy on an Ingress, and deletes the residue.

A failed cleanup keeps the residue and counts the attempt. The artifact is then `skipped`, so another site's broken Caddyfile or pool never halts the fleet rollout, and the next converge tries again. Node removal deletes the residues with the Node.

The fleet catch-up converges a Node only when it belongs to the [rollout set](/reference/gateway-recovery#rollout-set-and-order). On another Node, such as the Gateway's own, run `orbit node:converge NODE` yourself. `node:converge` needs an `active` Linux Node whose WireGuard address and SSH host key the Gateway manages. The residue of any other Node stays until that Node qualifies again or is removed.

Instance removal deletes a Route whose last target it removes, before it finalizes the source. A development removal first serves `503 Orbit Route unavailable` for the Route from stored state. Then it clears the site publication, builds Caddy without the Route, removes the Instance and Router certificates, and deletes the Route. A production removal republishes the remaining pool when a shared Route keeps other targets. [Instance removal](/reference/instance-removal) owns the cascade.

| Removal | Guard |
| --- | --- |
| Project | Refused while it owns a Route (`project.has_routes`). |
| Cluster | Refused while it scopes a Route (`cluster.has_routes`). |
| Node | Refused while a Route uses it as scope, target host, or generation basis (`node.has_routes`, or `route.reconciliation_required` for an active Route). |
| `app-dev` or `app-prod` role | Refused while the Node hosts a Route target. |
| `ingress` role | Refused while a Route in its Cluster is public. |
| Cluster Router | Refused while the Cluster owns a Route. |

## Coordinate publication

Only one operation publishes private Caddy, DNS, or Metrics configuration at a time. The Gateway takes an exclusive lock on one file, `$ORBIT_HOME/.dnsmasq-projections.lock`, before it reads Route, target, Cluster, and Router state. It holds the lock through Caddy builds, DNS publication, and activation. Nested calls in one request share it. A competitor waits up to 30 seconds, or the rest of its command time when that is shorter. Then it returns HTTP 409 `app-dev.projection_busy` before it changes anything.

Each Cluster also has a Router lock. Setting or clearing a Router holds it through validation, setup, activation, and cleanup. Cluster state and TLD changes use it too. A competitor for the same Cluster waits up to 30 seconds and then returns HTTP 409 `cluster.router_busy`. Different Clusters use separate locks.

When an operation needs several owners, it takes them in this order: Node lifecycle or role, `app-dev` source, Cluster Router, Metrics lifecycle or credential, projection, then remote host. No database transaction stays open during remote work.

## Check Routes with Doctor

The `instance` family checks every active Route of an Instance on every Node that serves it. The checks change nothing and use only Nodes that the caller may address. An unselected related Node gives `instance.related_node_unverifiable`. A valid serving configuration stays healthy when the application returns HTTP 500.

Doctor skips an Instance in `removing`. A removal that lasts 10 minutes or more reports `instance.removal_stuck`. When a removal starts during an inspection, Doctor drops that Instance's findings. See the [Doctor family rules](/cli/doctor#what-each-family-checks).

| Doctor issue code | Difference |
| --- | --- |
| `instance.private_routing_scope_mismatch` | The Route's Node or Cluster scope differs from the target's placement. |
| `instance.router_caddy_mismatch` | Router Caddy does not match the Route. |
| `instance.workload_caddy_mismatch` | Workload Caddy does not match the Route. |
| `instance.private_certificate_mismatch` | A Route certificate is missing or stale. |
| `instance.private_dns_mismatch` | Private DNS does not answer the domain with the expected address. |
| `instance.private_firewall_mismatch` | Role firewall rules differ from the Route's expected rules. |
| `instance.laravel_url_mismatch` | A detected Laravel `APP_URL` differs from the Route domain, or from the domain of the Route that wins a [web-root directory](#serve-several-web-roots). The summary names that directory. |
| `instance.target_set_mismatch` | Router Caddy does not publish the Route's ordered target set. |
| `instance.route_association_mismatch` | An Instance has no Route without a web root, or more than one. |
| `instance.public_ingress_mismatch` | The Ingress Caddyfile lacks the public site that a build renders for it. |
| `instance.public_tls_mismatch` | The public site pins an Orbit CA leaf, lacks `tls force_automate` while the Node disables certificate management, or its Let's Encrypt certificate is missing or expires within the renewal margin. |
| `instance.private_forwarding_mismatch` | The Ingress cannot open a TCP connection to an address its public site forwards to. |
| `instance.public_firewall_mismatch` | The Ingress firewall is inactive, or it lacks a managed rule for port 80 or 443. |
| `instance.related_node_unverifiable` | A required related Node is outside the selected set. |
| `instance.inspection_failed` | A required observation is missing, malformed, or unreachable. |

Doctor builds the expected public site the same way the build does. The Gateway sends that site and the intended forwarding addresses to `doctor.inspect-public-route`; Rust reads the live Caddyfile, probes those addresses, and checks the certificate on the public listener. The certificate check trusts only Ubuntu’s packaged Mozilla roots, verifies the domain, and applies no local Orbit CA trust.

It also checks that a public Route's Let's Encrypt certificate exists and does not expire within the renewal margin, defined as one sixth of that certificate's lifetime from `notBefore` to `notAfter`. This relative margin avoids raising an alarm at Caddy's own renewal point and scales to shorter certificate lifetimes. While a public Route is mid-issuance and the Ingress serves no valid public certificate yet, Doctor can report a transient TLS issue; it clears once issuance completes.

The forwarding check dials the Router, or the workload Nodes when the Ingress is also the Router. A site that serves the target directly forwards nowhere, so it always passes.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Routes own domains, and Cluster membership owns scope

A Route keeps its domain with zero targets or with targets on several Nodes, so the domain lives on the Route, not on the Instance. Routing scope follows active Cluster membership, not the domain or the presence of a TLD. A TLD-less Cluster can then route explicit production domains through its Router.

### Domains and hostnames

A Route owns an application domain. A machine or network identity is a hostname. The two terms stay apart in the API, SDK, CLI, and stored data, so a placement change can show which name changes. The API has no `hostname` alias for a Route domain.

### One own Route per active Instance

An application needs one canonical URL, and Laravel's `APP_URL` must agree with it. So each Instance has one Route without a web root, and an active Instance without it would break the promise that active means reachable. A Route with a web root serves another directory of the same checkout: it is another site, not a second URL for the same application. The oldest-Route rule keeps `APP_URL` deterministic when two such Routes serve one directory.

### Active Cluster TLD first

A generated domain in an active Cluster with a TLD uses the Cluster namespace, so it names the Cluster that routes it. Node-first naming is the rejected alternative, because a Cluster member would keep a Node namespace. Explicit domains never follow TLD changes, because they are the operator's choice.

### Generated identity follows the target

A generated Route takes its TLD from its target's Node, and it keeps the last generation basis when the target is cleared. Binding the Route to its first Node would keep an obsolete namespace and a permanent dependency on that Node. A generated Route stays single-target, because a pool has no single Node to name it.

### Domain changes create a replacement Route

An immutable domain per Route makes authority and recovery explicit: the old Route stays authoritative before cutover, and the new one after. Changing the domain in place would need old, candidate, and progress fields on one record. One generic inactive state is rejected, because preparation, failure, and retirement need different retry and cleanup behavior. A shared pool moves as one, because one domain cannot cut over per target.

### Separate Ingress, Router, and workload

Ingress owns the public listener and certificate. The Router owns backend selection. Workload Caddy owns the site that serves the Instance. A workload move or a pool change therefore never changes public DNS or the public certificate. Direct public access to a workload is rejected, because it would expose placement and split TLS and firewall ownership.

### Round-robin without affinity or replay

Round-robin spreads load without changing the public endpoint. Backend affinity is rejected, because a shared session store already keeps sessions across targets. Application-health checks are rejected, because an HTTP error does not show whether Orbit provisioned the placement. Request replay is rejected, because a failed request can already have had side effects. Waiting for requests in progress before removal is rejected, because the application would then decide when removal ends.

### An empty Route is deleted with its last Instance

A removed Instance does not need its domain, so Instance removal deletes a Route whose last target it removes and releases the domain at once. A shared Route stays while other targets remain. A retry after source cleanup cannot restore the deleted Route.

### LAN first, without fallback

A configured LAN address is operator intent. A LAN path that fails shows as a visible error instead of a silent switch to WireGuard, which would hide wrong intent and make the path unclear.

### WireGuard membership is the private trust boundary

Access grants authorize Orbit commands. They do not describe which services Nodes may reach. Applying grants to private traffic would need a per-port authorization model. Limiting private traffic to Route HTTPS would block the general connectivity that trusted Nodes need. So removing a Node from WireGuard revokes its private network trust. [Task VMs](/reference/compute-drivers#limit-fleet-traffic-on-the-hub) are untrusted peers in a reserved range. Their bridge ACL, hub filter, and Caddy guard limit private traffic. The optional Swamp hostname admits one HTTPS site for worker enrollment and execution, without trusting task VMs to reach other private sites.

### One publication field and Let's Encrypt on public Ingress

A second field for public readiness would repeat what `status`, `replacement_step`, and Doctor show. Browsers and strict proxies do not trust Orbit CA, so a public site needs a public certificate. A fallback from Let's Encrypt to Orbit CA is rejected, because that silent downgrade breaks a working public host. A public Route that is not yet eligible keeps its intent instead of failing, so operators can store public intent before an Ingress exists.

### Development servers on the Route origin

Cluster DNS sends the browser to the Router, not to the workload Node. So the development server shares the Route's domain and port 443 under a reserved path. A shared Cluster port, a second domain, and direct Node access are rejected: each splits the Route's single path and certificate.

### Custom proxy Routes for Node services

A Node service that is not an application still needs a unique name, private DNS, a certificate, `route:list`, and Doctor. A synthetic Instance would attach the wrong lifecycle. A separate proxy resource would duplicate Route identity. Operator-supplied Caddy or certificate files would leave unmanaged configuration on the Node.

### Public Process Routes select an edge separately from the workload

A Process can run on a service Node outside any Cluster. Selecting an Ingress independently keeps the service Node's membership and private Caddy sites unchanged. The Process Node's Cluster supplies the natural default; outside a Cluster, the existing public app Routes identify the production edge without a hard-coded Node or Cluster ID. Ambiguity requires an explicit selection. Routing scope follows the selected Ingress internally.

LAN address selection uses the recorded /24, not Cluster membership; the source firewall check and connection probe confirm the path before activation. Requiring a listener on the selected address avoids silently rewriting Docker publications. Arbitrary remote URLs, wildcard bindings, a Router hop, and a private certificate on the service Node add exposure or complexity without helping this HTTP path.

Route and certificate dispatch takes a kind and worker identity. The placed family invocation claims and executes on the same worker Node. Before its first effect, it saves the claimed Operation ID. A retry keeps that ID and verifies a saved terminal result through the Gateway. An empty claim ends the invocation. A lost lease ends the attempt; a later dispatch may reclaim expired work.

Swamp Route composition uses Node-bound children. `route.caddy_converge` takes `{reason}` and renders the whole Caddyfile from Gateway state. A changed render, listener address set or Caddy serving role refuses before publication. A failed Caddy child includes `stage: render|validate` only when its Gateway render receipt or target validation receipt proves that stage. Completion checks the stage against that recorded failure, including after receipt recovery. Other failures supply no stage that permits first-Node baseline degradation. The Node build lock covers the fresh check and dispatch, so an older render cannot replace a newer serving configuration.

Instance removal calls `route.withdraw_instance` with `{instance_id}` before it removes source files. This converges shared PHP-FPM pools even when the Instance has no Route. After source removal, `route.cleanup_instance` withdraws Caddy before removing the unused Instance certificate and runtime. A certificate still named by a stored site stays on the Node.

`route.offline_residue_clean` takes `{removed_node_id: int|null}` on the serving Node. A Node ID scopes cleanup through the removed Node's preserved Route associations. `null` selects retained residue whose Route row is absent. Successful cleanup removes only the captured residue rows that have not changed during execution. Its `removed` result is a redacted list of artifact names.

### Route child contracts

Route publication records child intent from stored Route targets and Node placement. Development workload preparation uses `route.workload_prepare` with `{route_id, instance_id}` on the workload Node. The Gateway binds checkout roots, inspects the shared PHP pools, selects packages from that inspection, and requires pool publication proof. A changed stored-site render refuses before a Node effect.

Production publication uses the Instance family's `instance.prepare_php_runtime` with `{instance_id}` on the production Node. A pending Route with a web root supplies its application pool through the owning Route relation. Environment changes use `instance.sync_environment` with `{instance_id, route_domain: candidate|authoritative}`. URL-only work uses the child key `synchronize-web-root-urls.<instance_id>`.

The Gateway captures `target_instance_ids`, `previous_target_instance_ids`, and `removed_instance_ids` at admission. The plan retains every admitted placement in `placements` and removed target snapshots in `detached_instances`, with `instance_id`, `node_id`, and `checkout_path` captured before association changes. A target disposition retains its previous ownership proof after associations change. Recovery preserves these snapshots after a removed Instance disappears. A replacement also records `candidate_route_id`. Secret environment values remain Gateway references.

Analytics publication uses `route.analytics_publish` with `{route_id}`. Host relocation uses `route.analytics_host_prepare` and `route.analytics_host_withdraw`, also with `{route_id}`. These Operations use the stored serving Node. Host preparation issues the private Router leaf before the site is published. Public Ingress keeps Caddy automatic HTTPS. `route.analytics_remove` withdraws the stored edge and tracking Route.

A `node.add` parent may update a generated Route domain with exactly `{route_id, domain}` when that Route records the parent Node as its generation basis. The tuple `{route_id, domain, node_id}` must match `state.enrollment_intent.route_changes`. The child Node follows normal Route admission: the first workload Node from target Instances ordered by Instance ID, then the Route Node, stored Router, or custom-proxy Node. The generation basis does not override current placement. The Node family retains the original TLD and Cluster until these children succeed.

A child `route.destroy` request uses `{route_id, offline: false}`. The shared child admission boundary calls `AdmitRouteOperationAction::destroy` when the parent requests the child. This runs the Route guards and captures its intent before the child becomes runnable. An AI takeover requests destruction after its collector health proof.

`route.private_dns_publish` requires an active VPN or Gateway role and uses the listener release and socket unit installed by Node convergence. It validates and replaces the catalog before enabling and starting an inactive socket. Publication requires the loaded catalog digest and a DNS reply. Before rollout, verify that each serving listener release writes catalog confirmation; a missing listener must first be installed by Node convergence. Node enrollment and Cluster changes commit their stored placement before starting DNS publication. A failed first publication restores the previous data and inactive socket state. An existing socket stays open during publication and recovery.

Each parent prepares exact `{stage, key}` child entries. Child-phase and residue result lists allow at most 4,096 entries and use the shared kit's string and whole-result limits. Opening a phase makes only those children eligible. A commit checks each child's stamped parent, admission snapshot and successful result through the shared child proof. Final proof checks the required phases and children again. The parent completion must match that recorded result. Admission prepares the complete forward and rollback plan in its state transaction. Every child must be registered and declared before the transaction commits. A missing child or shared capability returns `operation.kind_unavailable` with no reserved Route, replacement, role or runnable Operation. Retained Instance rename callers keep the synchronous Route update executor; HTTP and CLI Route updates admit Operations.

A single target binding grants development source access when the Route is not active. It preserves the Route's lifecycle and publication state and leaves the next Caddy build to its owning operation.

A target pool prepares workload certificates under temporary scopes before it changes associations. Gateway-recorded preparation selects those private sites for Caddy. The old associations stay authoritative during preparation. After association commit, environment synchronization and live certificate publication finish before Router publication and authorized Instance removal. A refusal before commit withdraws prepared Caddy sites before removing their certificates. A committed change recovers forward from the saved database proof. A retry keeps that proof until its own database phase commits. Failed Operations preserve the Route phase and error code for the next request. A completed rollback cannot be reported as successful publication.

Cluster Router parents use `node.role_converge` with `{assignment_id}` for the recorded candidate and `node.role_remove` with `{role: router, purge_data: false, offline: false}` for obsolete assignments. The Node family proves those effects. The Cluster parent keeps the candidate provisioning until certificate, workload and Caddy preparation succeed. It commits the new Router before publishing private DNS from that stored selection. A DNS failure after this commit recovers forward. A failed restoration before commit keeps the candidate serving until DNS restoration and the cache wait are proven. The old Router keeps serving until Swamp waits for the DNS grace period and the Gateway confirms it elapsed. Assignment rows are deleted only after their removal children succeed.

An initial production web-root activation that fails withdraws DNS and Caddy before its pool and certificate cleanup. Removal also updates the URL of the next Route serving that application directory. A late Metrics failure stays on its Operation and leaves the completed Route state intact.

Web-root updates record the new root at an explicit phase. Their Instance children read that stored root. A failed update restores the old root before starting separate rollback children; the URL-only rollback key is `synchronize-web-root-urls.rollback.<instance_id>`. Route updates request `metrics.reconcile` with an empty input only when one active Metrics assignment has an active serving Node. The Metrics family captures its reconciliation intent when that child is requested.

Route Operations retain their admitted Instance environment owners until completion. Admission and each executor run use the same environment locks as deploy, rollback and environment synchronization. While a Route Operation is open, unrelated Instance environment changes refuse with `env.operation_busy`; only its stamped descendants may use that owner. This keeps web-root links, Route URLs and production runtime selection on the same release through publication.
