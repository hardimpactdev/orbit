---
title: "Routes"
description: "What a Route records, how Orbit projects its private traffic path through a Node or Router, and which later changes it coordinates or refuses."
covers:
  - apps/gateway/app/{Domain,Actions,Infrastructure,Data}/Routes/**
  - apps/gateway/app/Http/{Controllers/Api/RoutesController.php,Requests/Routes/**}
  - apps/gateway/{app/Models/{Route,RouteTarget,RouteCustomProxy}.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php}
  - apps/gateway/app/Infrastructure/AppDev/{DevelopmentCaddyConfigRenderer,DevelopmentSiteRepository,NativeDevelopmentProjectionOperationLock,RemoteAppDevCertificateManager,RemoteAppDevRouteFirewallManager}.php
  - apps/gateway/app/{Domain/AppDev/{DevelopmentServerEndpoint,PrivateDnsAnswerExpiry}.php,Infrastructure/Clusters/NativeClusterRouterOperationLock.php}
  - apps/gateway/app/Infrastructure/Instances/{NativeDevelopmentRouteProjector,NativeDevelopmentSourceAccess}.php
  - apps/gateway/{app/Domain/Instances/DevelopmentSourceAccess.php,database/migrations/*_instance_path_proxies_table.php}
  - swamp/{ansible/route-create-site.yml,ansible/library/orbit_route_site.py,workflows/workflow-{operation-route-create,route-create-site,route-create-csr}.yaml}
---

# Routes

A Route is a domain that reaches an Instance or a Node service. This page explains the Route record, domain selection, private and public traffic, and the changes that Orbit coordinates or refuses. [`route`](/cli/route) lists the commands.

The Gateway validates Route changes and records intent as an Operation. Each available kind runs one native Swamp DAG, with Node effects as placed steps. The Gateway keeps Route state, ownership checks, read APIs and minimal result projection. Kinds whose native conversion is incomplete return `operation.kind_unavailable`; there is no polled fallback. Certificate signing runs in Swamp on the control worker.

Route creation and updates use Node phases and pinned SSH argv commands. Their TLS probes run on 209 over the captured SSH connection, verify the hostname and Orbit CA, and ignore the application HTTP status. A failed Caddy validation removes its candidate, leaves the live Caddyfile unchanged and stops reload. A successful validation permits atomic publication, then reload; a later reload failure retains the validated new file for retry.

The site phase converges certificates, their current links and account copies, root trust and the Caddyfile in one Node call. Private keys are copied only within their Node. Workspace access, `APP_URL`, shared environment links and PHP-FPM pool publication keep their existing order. Caddy validation precedes reload; the TLS probe still runs afterwards.

The site module writes a candidate with final owner and mode, validates that file, then atomically renames it over the live Caddyfile. A repeated publication retains the existing account-copy and Caddy reload behavior, so a retry completes activation even when its files already match. See [candidate publication](/reference/caddy-configuration#native-publication).

This site module is an accepted exception to rule 22 because it batches the stock modules’ Node round trips. On Beast, the isolated site phase fell from 3.260–3.308 seconds to 1.067–1.272 seconds. This includes Ansible startup and real Caddy validation/reload. This is not a general pattern: another custom module needs the same measurement.

An Instance create shares its already-written Route intent and shipped assets with the site child. The child reads that input and keeps its own scratch folder, SSH pins and cleanup. It cannot remove the parent's input. Standalone Route calls and other callers prepare their own child input. Both entry forms run the same site phase.

The Node CSR action creates its certificate directory as `root:caddy` with mode `0750`, then creates the private key and sets `root:caddy` ownership and mode `0640` before it returns the public request. Signing stays a separate Gateway behavior. The key never leaves its Node.

"Node 209" here is the Swamp Node of the production fleet: the Node that holds the `swamp` role. The Gateway finds it by that role and never by an id, so a Gateway that bootstraps its own machine has the same Swamp Node ([Gateway bootstrap prerequisites](/reference/node-provisioning#gateway-bootstrap-prerequisites)).

Domain, publication and web-root updates use the Route admission action. The Gateway renders every file the run writes: each Node's whole Caddyfile, PHP pools, `APP_URL` lines and certificate leaves.

The run follows the chart [`route/update.mmd`](/cli/route). It publishes the new site beside the old one, writes the new `APP_URL`, and probes TLS. Then it opens or closes the public edge, moves private DNS outside the TLD, waits the DNS grace, and withdraws the old site. There is no rollback.

An Instance removal withdraws its Route with the `route-withdraw` workflow, not the Route mutation. Each Node it touches runs `route-withdraw-node`, in parallel: one Ansible `read`, one composition and one Ansible `apply`.

- The composition is the same pool, Caddyfile and private DNS merge as the Route mutation, with the same revision checks.
- The captured states apply in order against what the Node holds, so each file is written once.
- A live file that matches none of the states stops the phase before it writes.
- The private DNS records leave in the same step as the Caddy site of another Node, not after it.

A pending domain replacement and an unchanged request change no Node, so their Operation has already succeeded and no run starts. Instance rename records its own Operation and composes the Route update.

A failure before the cutover leaves the old Route serving: a domain replacement is marked `failed`, and its new site serves no name. A failure in `Wait the DNS grace` or `Withdraw the old site` comes after the cutover, so the replacement becomes `active` and the old Route stays `retiring` with its `failed_step`. Each step has its own error code:

| Error code | Step |
| --- | --- |
| `app-dev.certificate_request_failed` | `Make a CSR on the Node` |
| `app-dev.certificate_sign_failed` | `Sign the CSR` |
| `route.publish_failed` | `Install cert, publish site` |
| `route.app_url_failed` | `Write the new APP_URL` |
| `route.probe_failed` | `Probe the site over TLS` |
| `route.ingress_firewall_failed` | `Open or close the edge` |
| `private_dns.publication_failed` | `Move private DNS` |
| `route.dns_grace_failed` | `Wait the DNS grace` |
| `route.withdraw_failed` | `Withdraw the old site` |
| `route.update_failed` | A failure outside these steps |

Caddy convergence starts `operation-route-caddy` after intent commits. It reads the current public render through the bound Operation GET. Swamp validates the candidate and runs the filesystem swap and Caddy service commands locally with sudo, then reports the result. The Node agent only observes; it does not publish Caddy. The Gateway neither executes Caddy nor exposes a Caddy publication primitive. The shared `swamp.direct_writers` setting accepts only `direct` for this kind; selecting the removed writer refuses admission. Rollback is a revert PR.

Route destroy and withdrawal follow the chart [`route/destroy.mmd`](/cli/route). Admission captures every Node the Route touches and renders each one's whole Caddyfile without the Route. The run removes private DNS outside the TLD and waits the DNS grace. Then each Node gets that Caddyfile and loses the Route's certificate scopes, and development pools converge. LAN tuples and public ports that no published Route needs close last.

The end check uses the `check` of the same official behaviors and a native assertion that each result is compliant: no site, scope or rule of the Route is left. A successful check command with a `non_compliant` result fails the removal.

With `--offline`, the run first tries each Node once. A Node that does not answer is skipped by every later step and keeps residue for `node:converge`; a VPN Node is never skipped. On success the Gateway deletes the captured Route rows; a withdrawal keeps them. A failed removal leaves the Route as it was, and its Operation records the failed step:

| Error code | Step |
| --- | --- |
| `private_dns.publication_failed` | `Remove private DNS` |
| `route.dns_grace_failed` | `Wait the DNS grace` |
| `route.withdraw_failed` | `Withdraw site, remove cert` |
| `route.pool_converge_failed` | `Converge PHP-FPM pools` |
| `route.lan_firewall_failed` | `Remove unused LAN rules` |
| `route.removal_check_failed` | `Check nothing is left` |
| `route.withdrawal_failed` | A failure outside these steps |

Every composing workflow that forwards an environment execution copy declares `orbit-control-inputs`. This includes both Route mutation phases and the Cluster Router root and publication phases.

Withdrawal captures remaining application URL writes at admission. Public write inputs and pinned target connections stay in the run intent; environment material uses names-only `orbit-control-inputs` execution-copy descriptors. The existing `route-environment-write` child resolves the whole vault value through official SSH stdin from Node 209. Removal declares no private-material callback. Offline selection skips writes for retained Nodes.

Private DNS publication runs as `route-dns-publish` inside its owning native parent. Neither direct workflow uses the scheduled result read, claims, leases or Gateway execution locks. Follow the Swamp run before retrying an uncertain admission. The remaining Route lifecycle kinds refuse admission until their native conversion is available.

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

Use the native Route Operation result for Route status. [Doctor](/cli/doctor/doctor#what-each-family-checks) does not repeat Route lifecycle or configuration validation.

## Select a domain and scope

Supply a domain when you create an Instance to get an explicit Route. Otherwise Orbit generates the domain from the effective top-level domain (TLD):

1. The TLD of the Node's active Cluster, when that Cluster has one.
2. The Node TLD.

When neither exists, creation stops before any source or runtime change. An `app-dev` Node must have a Node TLD or belong to an active Cluster with a TLD. An `app-prod` Node can have no TLD, because production creation supplies an explicit domain.

| Instance name | Generated domain with effective TLD `test` |
| --- | --- |
| `default` | `<project>.test` |
| Any other name | `<instance>.<project>.test` |

The Conn [Project directory and name check](/reference/conn#threads-in-an-orbit-project) preview this same effective TLD without creating a Route.

The source branch does not change the generated domain. `instance:create <project> <node> default --branch=release` still generates `<project>.test`.

Cluster membership decides routing scope, independently of the domain. A Node in an active Cluster uses Cluster scope, also when the Cluster has no TLD and the domain uses the Node TLD. Every other Node uses Node scope. A Cluster that owns app Routes needs exactly one active Router. Public Process Routes select their edge independently, as [public Process hostnames](#public-process-hostnames) describes.

A Node keeps its own TLD while it belongs to a Cluster. [cluster](/cli/cluster#placement-and-tlds) lists which Node or Cluster may own a TLD.

### Generated domains after a Project slug update

A Project slug update recomputes every generated development Route domain from the new slug, the Instance name, and the effective TLD. Orbit creates a replacement Route for each domain that changes. The replacement keeps the Project, scope, provenance, publication, and target. Explicit domains never change. A default-branch update changes no Route. [Projects](/reference/projects#update-a-project) owns the update lifecycle.

## Create and change targets

Create an explicit app Route for an Instance with `route:create <instance> <domain> [--publication=private|public] [--web-root=PATH]`. The Instance ID determines the owning Project and the Node or active Cluster scope. Publication defaults to `private`. The Route targets that Instance and becomes active; an identical retry for an existing active Route returns that Route.

Creation reserves a `pending`, unpublished Route and starts one native Swamp workflow. Swamp prepares and publishes it before the Gateway records it active. An unchanged pending reservation can be retried. Failed or retiring Routes refuse a creation retry; their retained intent is available for explicit convergence. An unchanged active retry changes no Node: its Operation has already succeeded when the request returns, and no run starts. This form does not accept a Project argument, `--target`, `--node`, or `--cluster`. The custom proxy form is `route:create <domain> --node=NODE --upstream=URL` or `route:create <domain> --node=NODE --process=PROCESS`. Public Process Routes use `--process=PROCESS [--ingress=NODE] --publication=public`.

The workflow, `operation-route-create`, has the seven steps of the [route:create chart](/cli/route/create). Each Node makes its key and CSR, and 209 has each CSR signed. A Cluster Route then allows the Router on LAN. One `route-create-site` child per Node installs the certificate and publishes the site. Then 209 probes the site over TLS, and a public Route opens the Ingress edge. A domain outside the TLD gets its private DNS beside the site install, because it needs nothing from the site.

Each Node's `route-create-site` child is one Ansible phase, `site`, of `swamp/ansible/route-create-site.yml`: the account, the Caddy access ACLs, PHP-FPM pools (each unit is reloaded and enabled; a stopped one starts), certificate, trust store, Caddyfile, APP_URL lines and awake markers. A production `.env` is written by its own steps after it. The other Node changes are official `@swamp/cfgmgmt` behaviors or one `@swamp/ssh exec` command. A failed step leaves the Route `pending` with its `failed_step` and `error_code`. There is no rollback.

The phase updates the system trust store only when it wrote a new Orbit root.

The Gateway builds the Caddy access ACLs as five commands for one site: two that deny the checkout, one that opens passage above the served roots, and two that grant read on the roots. Each command names every path it covers, because each command is one Ansible task on the Node.

A Laravel Instance registered in place can be active without a Route. If registration reserves a Route, that Route must become authoritative before the Instance activates. Other Laravel activation and Route ownership constraints still apply.

[`instance:create`](/cli/instance/create) of a web-serving Project runs `operation-route-create` beside its setup steps in its native run. Its Instance is still `reserved` when the Gateway captures the Route plan, so the capture renders that prepared Instance as its site: the Caddy site, the PHP pool, and the TLS probe.

[Registration](/cli/instance/register) with `--domain` runs `operation-route-create` inside its native run. Instance child workflows bind certificate signing to the parent Operation and its run. Create and registration may sign only their admitted forward Route leaves, never restoration leaves. [`tasks.provision`](/reference/tasks#provision-an-external-group) runs the same child for a routed task workspace and signs under the same rule. The Route preserves the checkout's `.env` and changes only application URL lines when needed. An identical registration retry reuses its reserved Route; another owner's domain is refused.

Registration captures the pending Instance's prospective site, PHP pool and TLS probe without activating its stored record. The ordinary site catalog excludes reserved Instances. The Route must answer over TLS before registration activates the Instance. The owning Instance parent can ask the site child to mark a newly prepared development runtime awake after publication. Standalone Route creation preserves the current awake state. A completed registration retry can mark it awake only after setup verifies all required Processes.

[Instance cloning](/reference/instance-cloning#preview-route) runs the same CSR, sign and site children for its pending preview Route inside the clone run. The clone writes the whole `.env` itself, so the preview Route's site step writes no second copy. The clone's success activates the Route.

```bash
orbit route:create 12 shop.example.test
orbit route:create 14 shop.example.com --publication=public
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
| `route.instance_inactive` | The Instance is neither being provisioned nor active. |
| `route.node_inactive` | The custom proxy's Node is not active, or has no WireGuard address. |
| `route.upstream_invalid` | The upstream is not a loopback HTTP URL. |
| `route.upstream_unresolved` | The Process has no single supported listener for this publication. |
| `route.process_conflict` | The Process is not a Node Process on the serving Node. |
| `process.runtime_lock_failed` | Process Route creation could not acquire the Process runtime lock within its bounded wait. No Route was reserved. |

A Route target must have a supported relative web root. An Instance rooted at `.`, such as a package, returns `route.target_web_root_unsupported` until an operator sets a web-root override.

| Change | Result |
| --- | --- |
| Set the current target again | The unchanged Route. On a Route that is not `active`, the Gateway grants the target's source access again. |
| Clear an empty Route | The unchanged Route; the CLI reports `Skipped — no target (route.target_unchanged)`. |
| Set an Instance that belongs to another Route | `route.target_conflict`. Both associations stay. |
| Replace or clear a target, or remove the Route, when that detaches an active Instance | `route.target_conflict`. Nothing changes. |
| Clear the target of a replacement Route that never activated | `route.target_conflict`. Remove the replacement with `route:destroy` instead. |
| Change the target of an `active` Route | `route.reconciliation_required`. |
| Set an Instance of another Project, or an inactive Instance | `route.target_app_conflict` or `route.target_inactive`. |
| Set a generated target without an effective TLD | `route.tld_required`. |
| Change a Route of another kind | `route.kind_unsupported`. |
| Set a target while another operation holds the projection lock | `app-dev.projection_busy`. Nothing changes. |
| Set a development target on a published Route when the publish step fails | `route.publish_failed` at step `Install cert, publish site`. The target stays set. |

Setting a target on a generated Route moves its generation basis, scope, and domain with the target. When the domain changes, a replacement Route takes the target.

Setting a development target on a Route whose sites are published moves the site to the target's checkout with `route:create`'s publish step. `instance:create` already gave Caddy access to that checkout. A Route whose sites are not published only records the target: its Operation has already succeeded, and the Route publishes when it converges. [Native Route targets](/reference/native-route-targets) describes the run.

The Gateway takes the [projection lock](#coordinate-publication) before it reads the Route, so `app-dev.projection_busy` returns before anything changes. The target is stored before the run starts. When the run fails, the new target stays set. Set the same target on that Route again.

### Change a production target set

An operator sends the complete ordered Instance set for one explicit, Cluster-scoped production Route. Every target must be an active production Instance of the Route's Project, on a distinct active `app-prod` Node in the same Cluster. The Cluster needs no TLD. The request can take an Instance from another Route in that Cluster.

A change that detaches an Instance must name it in `dispositions`, also when the Instance is not active. Each disposition either reassigns the Instance to a compatible explicit Route or authorizes its removal. A `pending` destination Route becomes `active` when it receives a reassigned Instance. No request removes an Instance unless `remove` is true for it. The Gateway refuses caller-supplied backend URLs, Node addresses, Caddy directives, and balancing fields.

One run stages each added target's runtime, certificates, Caddy site and `.env`, and probes it over TLS before the target joins the Router pool. Then it switches the Router pool and retires what no Route needs. An identical completed request changes nothing. [Native Route targets](/reference/native-route-targets) lists the steps and their error codes.

The Gateway records the requested set before it starts, and writes the new associations from the run's result. A failure before the Router switch keeps the original associations; staged sites serve no traffic. A failure after the switch writes the new associations, because the new pool serves, and keeps the recorded set and `failed_step`. The recorded set stays until the change completes, so the same request runs again and a different request returns `route.target_set_conflict`. There is no rollback.

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

An Instance in a container is served by FrankenPHP in the container. Its Route's site proxies to port 8080 of the container's bridge address, with no PHP pool or checkout access on the Node. See [the default Instance](/reference/development-containers#the-default-instance).

A private Route of a clone also gets a development site on port 444 of the Node's WireGuard address. The Node redirects WireGuard connections to its free ports from 1024 to this site, which proxies to the port the client named in the container, such as `https://<domain>:5173`. A public Route gets none. See [Agent development servers](/reference/development-containers#agent-development-servers).

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
| Transfer | The Routes move with the Instance and keep their IDs and domains. A public one cannot change Cluster. See [Instance transfer](/reference/instance-transfer#placement-and-publication). |
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

A web root must not hold a link, the same rule as for the Instance root. So no web root of the Instance, its own root included, may contain the `.env` of a served directory. A web root that is its own application directory, such as `apps/docs`, or one inside the Instance root, such as `public/docs/public`, returns `route.web_root_unsafe`. A web root in the default directory must be the Instance root itself; `apps/site` on root `apps/site/public` returns `route.web_root_unsafe`.

A Route that repeats the Instance root reuses the primary application, PHP pool and stable `<home>/.env`. Its preparation does not add that root to the list of separate applications or relink its existing `.env`, including before the Route target association is published. Doctor's `APP_URL` check of each directory skips production. A cached configuration in a release keeps its old `APP_URL` until the next deployment rebuilds it.

Follow-up: a transfer that moves a public Route with a web root to another Cluster.

## Custom proxy Routes

A custom proxy Route publishes an exact domain for a service that already runs on one managed Node. It creates no Project, PHP handler, or document root.

```bash
orbit route:create executor.orbit --node=7 --upstream=http://127.0.0.1:4788
orbit route:create executor.orbit --node=7 --process=21
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

### Stored private TLS upstreams

A managed service relocation can retain its private Route and DNS on the serving Node while forwarding over TLS to another active Node's WireGuard listener. This is a stored recovery intent, not an additional `route:create` input: the public API still accepts only loopback HTTP URLs. An approved relocation records the backend Node in the custom proxy's `node_id` and an HTTPS URL with an explicit port in its `upstream` field. The Route's `node_id` still identifies the serving Node, and both Nodes remain protected from removal.

The renderer requires that the address belongs to an active managed Node and that the serving Node already has access to it. It refuses public addresses, hostnames, credentials, paths, query strings and fragments. Caddy verifies the upstream against the Orbit CA, sends the Route domain as Host and TLS SNI, uses HTTP/1.1, and preserves streaming and WebSocket upgrades. Rebuilds derive this configuration from stored Route state. This operation creates no access edge, firewall opening, certificate or DNS change.

The Doctor probe for custom proxies accepts only loopback addresses. For this remote variant it reports `route.inspection_failed`. Verify the rendered site and authenticated service response during recovery.

### Public Process hostnames

Give a Node Process its own public hostname through a selected Cluster Ingress.

```bash
orbit route:create sync.example.com --process=267 --publication=public
```

The API body is `{"domain":"sync.example.com","process_id":267,"publication":"public"}`. Add `ingress_node_id` or CLI `--ingress=<node>` to select an active Ingress by ID (the CLI takes only numeric IDs). By default, Orbit selects the public Ingress of the Process Node's Cluster. For a Process Node outside a Cluster, Orbit selects the single active Ingress serving existing active public app Routes. No candidate returns `route.ingress_required`; multiple candidates return `route.ingress_ambiguous`. An explicit Ingress must be active with an active `ingress` role.

The stored Route has that Ingress's Cluster scope. Orbit never changes the Process Node's Cluster membership or Caddy sites. The caller needs command access to the selected Ingress and the Process Node; unrelated Cluster members need no grant.

The Process must be an active Node-owned Docker Process on an active Linux Node. When both Nodes have recorded IPv4 LAN addresses in the same /24, Orbit selects the Process Node's LAN address; otherwise it selects WireGuard. Cluster membership does not affect address selection.

The Process must publish exactly one TCP listener on that selected address. A missing or ambiguous listener returns `route.upstream_unresolved` before storing intent. Orbit never rewrites published ports or falls back from a missing LAN listener to WireGuard. Add the LAN publication with `process:update` first. Loopback, wildcard, and UDP listeners are ineligible. A raw `upstream` stays private and loopback-only. Public Process IDs are numeric. Custom proxy Routes cannot change domain, scope, target, or publication after creation.

The selected Ingress terminates public TLS with the same ACME policy and `ingress-firewall` activation step as public app Routes. It sends HTTP directly to the resolved Process listener, keeps Host, streams without buffering, permits WebSocket upgrades, and allows one hour between upstream reads and response headers. It needs no Router hop or Router role, and installs no Caddy site or certificate on the Process Node. Private DNS answers with the Ingress. Set public DNS yourself; a Cloudflare proxy can front the public hostname.

Activation verifies the Process Node's firewall and sends an HTTP HEAD request from the Ingress to the selected listener before publishing. WireGuard uses the existing `orbit:wireguard-members` trust rule. LAN needs an active managed `orbit:` TCP allow rule for the Ingress LAN source and selected port; a rule for another port, source, or network does not qualify. Orbit refuses activation with `route.upstream_firewall_mismatch` when this rule is missing, and `route.upstream_unreachable` when the listener cannot be reached.

The Gateway supplies the selected address, port, Route domain and eligible managed firewall shapes to the native workflow. Swamp checks the Process Node firewall and sends HEAD from the Ingress with the Route's Host header, bypasses HTTP proxies, and waits at most ten seconds. Any HTTP status proves reachability; it does not follow redirects or require application success.

It keeps the activation for retry and Doctor reports the blocked path. Orbit adds no hidden firewall opening or Process port rewrite. Use the managed [firewall commands](/cli/firewall) to allow the selected LAN source before retrying. Docker publications bypass UFW, as that page explains; this check requires recorded firewall intent and does not make UFW an isolation boundary for Docker.

Node convergence restores the WireGuard member rule; Ingress convergence rebuilds the public site and HTTP rules. Removing the last public Route closes the public Ingress rules, including after offline residue cleanup. Process removal stays refused with `process.has_routes`.

Configuration and image updates are allowed when they preserve the Route's resolved upstream; see [Process updates](/reference/processes-and-schedules#update-in-place). The Route keeps its ID, active state, site, certificate, DNS, and firewall rules. A container replacement can briefly interrupt requests while the listener restarts. Caddy reconnects to the same upstream; no Route activation or rebuild is needed.

Process Route creation and Process updates serialize their admission transactions in the Gateway database. Creation reads and resolves the current Process listener inside that transaction. If creation reserves the Route first, an update must preserve that Route's listener.

The first [Node Caddy build](/reference/caddy-configuration#replaced-configuration) on a Node backs up and stops serving any hand-placed Caddy site. Create a custom proxy Route for such a site before that build. [Migrate an unmanaged Executor hostname](/solutions/migrate-unmanaged-executor-hostname) shows an example.

## Set up private traffic

Route publication, withdrawal, and rollback send private DNS children only to active Nodes with an active `vpn` role. A separate Gateway is a DNS client, so its `gateway` role does not make it a publication target. When both roles share a Node, that Node receives one child. The Node footprint stages the listener release and units; the publication child validates and writes its catalog before activating an installed but inactive socket. A missing socket remains a refusal.

The Gateway prepares the runtime, certificates, Caddy sites, and firewall rules of a new Route before it publishes private DNS. It publishes DNS last and then marks the Route and Instance active. It does not wait for the application to answer successfully.

### Node scope

Private DNS points the domain at the workload Node. Its Caddy terminates HTTPS with an Orbit certificate authority (CA) certificate and serves the Instance's web root.

Before the Gateway publishes a development Route, it gives Caddy read access to the web root and traversal access to its parent directories. Route publication skips missing optional Laravel storage paths, including their missing parents; it creates no checkout directories. A missing web root still fails. The site phase records Ansible failure text on stderr; secret-bearing tasks keep their output private.

Caddy cannot read the other source files. The web root must be inside the checkout. Symlinks in the web root are refused, except Laravel's `public/storage` link to the checkout's `storage/app/public`. When this preparation fails, the Gateway restores the previous permissions and reports `app-dev.source_access_failed` at step `source-access`.

This preparation walks only the Instance's checkout and served checkouts nested in it. Other checkouts on the Node keep the access their own Route granted.

#### Caddy access

Swamp publishes development TLS leaves under `/etc/caddy/orbit-certificates/<scope>`. It creates the shared `orbit-certificates` directory on first publication. That directory, the scope directory, its `versions` directory, and each certificate version belong to `root:caddy` with mode `0750`. Caddy can traverse the complete path to the certificate and its group-readable private key.

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

Private DNS points the Route domain and the Cluster TLD at the Router. [Private DNS](/reference/private-dns#cluster-router-addresses) describes which Router address each requester gets. A Route change names a Route in those answers only while the fleet it captures has a site for it. So a withdrawn Route, or a failed create that is undone, loses its answer in the same Operation.

A Router unset whose Router left no site, certificate or LAN rule on any Node ends at admission with no run. [Native Cluster Router](/reference/native-cluster-router#unset-a-router) has the steps.

Router Caddy forwards Orbit CA HTTPS to the workload Node and keeps the domain as the HTTP `Host` value and the TLS server name. The Router and the workload Node get separate private keys. The Router uses the workload Node's LAN address when the Node has one, and its WireGuard address otherwise. A configured LAN address that does not answer fails publication. Orbit never falls back to WireGuard, also not per target in a pool.

Route LAN rules keep the source-only tuple shape: allow TCP443 from the recorded Router LAN source on the workload Node, without a destination or Route comment. The Gateway captures the complete Node-wide set of unique tuples. Identical tuples are shared by Routes: withdrawal keeps one while another published Route still needs it. Route listeners and the Router's selected LAN upstream stay unchanged.

Route parents withdraw LAN tuples through the shared `route-lan-apply` child on Node209, using official SSH with the admitted host pins. The captured public input contains the desired tuples and existing target connection. It removes only tuples that no published Route still needs; every Node finished the one-time migration to these rules. LAN withdrawal starts no separate Operation. See [Route LAN firewall](/reference/route-lan-command).

Before publication, `route.verify-certificate` checks the admitted workload or Router endpoint from the control worker. It connects to the selected address on port 443, sends the Route domain as TLS SNI, and requires a chain trusted by the control worker. This preserves the existing OpenSSL chain check; it does not add a separate hostname check. The Gateway records the validated placement and public error mapping; Swamp drives the activation order. Router replacement uses the same commands.

Native Route parents run their captured TLS or HTTP probes inside their own workflow. A failed probe fails its parent run; verification starts no separate Operation. The admitted protocol, endpoint and Route domain stay fixed for that run.

Caddy and private DNS publication use direct workflows. The direct kinds expose no `/operation-executor` run allowlist. Caddy runs locally on its exact admitted Node; private DNS retains its existing publication primitive. Residue and Instance cleanup still use the shared transports until their owning cutover. The old `/route-publications` relay URLs are removed; callers use the shared bound Operation API.

The workflow uses the shared direct Operation read/result endpoints and no Gateway execution relay. The proven writers select `direct` through `ORBIT_SWAMP_DIRECT_WRITERS`; disabling one refuses admission instead of restoring the removed executor. Certificate mutations retain their current executor until their workflow uses sudo from the Node account and passes its cutover proof; verification needs no privilege change.

When the Router and the workload share one Node, one Caddy service serves the Route and sends the request to the local runtime. It never proxies to its own HTTPS listener. A pool that mixes a local and a remote target serves the local target through an internal Unix listener.

### Development-server endpoint

The reserved path `/__orbit/vite` serves live frontend assets and hot module replacement (HMR) on the Route's HTTPS domain, on port 443. For a checkout Instance, workload Caddy proxies that path to the assigned `vite_port` on loopback and keeps the path prefix. For a container with a migrated `vp-dev` Process, it uses the container’s bridge address and assigned port. When the Instance has no assigned `vite_port`, Caddy does not proxy that path. Router Caddy forwards it like any other path. HTTPS and WSS terminate with the Route's Orbit CA certificates.

Each development Instance on a Node has its own port, and separate Nodes can reuse a port. See [Assigned Vite ports](/reference/assigned-vite-ports). When nothing listens on the port, Caddy returns a proxy error for that path only, and never picks another Instance.

A development systemd Process gets these values from the Route domain:

| Variable | Value |
| --- | --- |
| `ORBIT_DEV_SERVER_ORIGIN` | `https://<route-domain>/__orbit/vite` |
| `ORBIT_DEV_SERVER_HOST` | The Route domain |
| `ORBIT_DEV_SERVER_PATH` | `/__orbit/vite` |
| `ORBIT_DEV_SERVER_PORT` | The Instance's assigned port |

A checkout Instance’s Vite server binds loopback and publishes the Route origin, as below. A migrated container preset binds `0.0.0.0` so the Node can reach it:

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

A Cluster has at most one active Ingress. The database refuses a second one. The public app edge exists only when all of these hold: the Route is Cluster-scoped, the Cluster is active, the Cluster has an active Router, and its Ingress serves. An Ingress serves while its role is active, while it converges, and after a failed convergence step. A new public activation, or its repeat on deploy, starts only while the Ingress role is active. When a condition is missing, the Route keeps `publication=public` but gets no public listener, certificate, or firewall opening.

A public edge is live when the Route is `active` or `activating`, its Cluster is eligible, and its `replacement_step` is at or past `public-activated`. A finished public Route stores `ingress-firewall`, a later step. A public Route whose `replacement_step` is empty has not finished activation and has no live public site.

The public app Ingress site names the domain and forwards to the Router. It never names an Instance, a workload Node, or a backend pool, so the Router keeps backend selection. Ingress forwards Orbit CA HTTPS to the Router's LAN address when the Router has one, and to its WireGuard address otherwise. It keeps the original `Host`, the HTTPS scheme, and the client address.

Public TLS terminates on the Ingress with a Let's Encrypt certificate that Caddy obtains through `tls force_automate`. Orbit never pins an Orbit CA leaf on a public site, and a Let's Encrypt failure never falls back to Orbit CA. [Caddy configuration](/reference/caddy-configuration#public-ingress-certificates) describes the site block. Create the public DNS record yourself. Orbit calls no DNS provider API and stores no public IP address.

When Ingress shares a Node with the Router, with `app-prod`, or with both, one Caddy service serves the public Route and never proxies to its own public listener. When the Ingress Node runs a target of the Route, its public site serves that target directly and replaces the target's private site for that host. A Router on another Node then accepts the public certificate, because the Node's system roots include the Orbit root. Until Let's Encrypt issues the certificate, that host does not complete TLS on the Node.

On an Ingress Node, only public sites bind every address. Router and workload sites stay on the WireGuard and LAN addresses, as [listener addresses](/reference/caddy-configuration#listener-addresses) describes. `ingress` and `gateway` never share a Node: `node:role:add` refuses `ingress` on the Gateway Node, and `node:role:relocate` refuses `gateway` onto an Ingress Node.

The Ingress firewall opens `orbit:ingress-http` (port 80) and `orbit:ingress-https` (port 443) only while the Cluster has at least one live public Route. When the last one leaves the public edge, the Ingress firewall step closes both rules. A new Ingress Node without a public Route has ports 80 and 443 closed to public traffic: UFW denies incoming traffic by default, and neither `orbit:ingress-http` nor `orbit:ingress-https` is present. Its only managed firewall rule is `orbit:wireguard-members`, which allows private WireGuard traffic.

### Publish the public edge

A publication-only change keeps the Route ID and the domain. To publish, the Gateway verifies the private hops, stores `public-activated`, and builds the Ingress Node, so Caddy can obtain the certificate. Then it opens the Ingress firewall and stores `ingress-firewall`. The public site stays unreachable until those steps succeed, also when another Route already keeps the Ingress ports open. A failed step returns its error to the caller. On a Route that is not `active`, it also stores `failed_step` and `error_code`.

Nothing in Orbit waits for or watches certificate issuance. Caddy requests the certificate after the build and retries on its own. Other issuance failures appear in Caddy's log on the Ingress Node and as a TLS failure for clients.

A change of both domain and publication reserves a replacement Route with the new publication. The current Route stays authoritative until cutover, as in [Change an explicit domain](#change-an-explicit-domain). An app Route can be public only when its targets are production Instances. A [public Process Route](#public-process-hostnames) has no Instance targets.

The Gateway refuses to remove an Ingress while any Route in its Cluster has `publication=public`.

### Ingress removal

`node:role:remove NODE ingress --force` is refused with reason `public_routes_attached` while any Route in the Node's Cluster has `publication=public`. Make each such Route private or remove it first. The Gateway checks this again when it claims the assignment.

The claimed assignment is `removing`, so it serves nothing. The Gateway builds the Node's Caddyfile: the public sites leave, and the Node's other sites stay on their private addresses. Then it closes `orbit:ingress-http` and `orbit:ingress-https` and reconciles service metrics. Caddy stays installed. When Ingress was the Node's last role, the Gateway reopens public SSH before it deletes the assignment.

The same command removes an Ingress whose convergence failed. A failed step leaves the assignment `failed` with `failed_step=remove:STEP` and an `error_code`. Run the same command again to retry. A failure in the build uses `ingress.caddy_config_failed`, as [When a build fails](/reference/caddy-configuration#when-a-build-fails) describes. With `--offline`, the Gateway removes an unreachable Ingress on its side only and lists what stays on the Node under `retained_on_node`.

## Change an existing Route

A Route change never changes Instance source, Nodes, Clusters, or checkouts. Changes that move a Route between placements or domains follow these rules:

- The Gateway verifies the new Caddy sites, certificates, firewall rules, and Laravel URL before it publishes the new domain or scope.
- A failure before publication leaves the old Route authoritative and the replacement `failed`; nothing is rolled back.
- A failure after cutover recovers forward, so a Route never has two authoritative domains or scopes.
- Each failure records `failed_step` and `error_code`. A retry with the same request checks the finished steps and resumes at the first unverified step.
- A conflicting request is refused.

For a development Laravel source, the Gateway sets `APP_URL` in the environment file and the cached configuration without Composer, Artisan, or application bootstrap. For a production source, it renders the stored environment against the new Route and replaces only the home's `.env`. It runs no deployment or restart commands. A stale cache or an HTTP error does not block a valid change. See [Instance environment variables](/reference/environment-variables#synchronize-during-a-domain-change).

### Change a TLD, Cluster state, or Cluster membership

These changes reconcile every private Route that depends on the affected Node or Cluster before the change becomes authoritative: a Node TLD change, a Cluster TLD change, Cluster activation or deactivation, and a Node attach or detach. The Gateway checks every affected Route, and a Node's LAN address against the Cluster, before the first Route moves. It compares each proposed domain with every Route domain in the fleet. One invalid or occupied result refuses the whole change and leaves every Route in place.

A change that would alter the domain or scope of an active public Route is refused with `route.reconciliation_required`. That covers Cluster activation and deactivation, a Cluster TLD change, and a Node attach or detach.

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

Route and Cluster Router operations compose the native `route-certificate-publish` and `route-certificate-remove` children inside their parent run. There is no standalone certificate CLI, API or Operation kind. Admission records each scope, Node account, domain, version and public root hash.

Node 209 reaches each admitted Node through pinned official SSH and copies no script to it. Per leaf, `route-certificate-publish` makes the version folder, runs one `openssl req` on the Node, has 209's signer post the CSR to the Gateway, and installs the signed leaf with `cfgmgmt/file` and `cfgmgmt/link`. It then installs the account copy that development servers read and trusts the Orbit root. The Node keeps its leaf private key. The Gateway signing endpoint checks the numeric Operation and its run, then returns the public leaf and root. The CA private key stays only in the Gateway.

Removal follows Caddy withdrawal. It removes only the admitted scope; it does not read the Caddy configuration again. The parent Operation projects the complete native result; certificate children create no separate Operations.

A `pending` replacement renders a site only after its certificate step completes. A `failed` replacement renders no site. Cleanup issues the live certificates, stores its `cleanup` step, rebuilds the workload and Router Caddy, and only then removes the staging certificates. So a failed cleanup never leaves a site that names a missing certificate.

### Change an Instance Route domain

`instance:rename --domain` is not available: it returns `operation.kind_unavailable`, also when `--branch` is supplied, and changes nothing. The rest of this section describes the replacement path that an Instance-owned domain change will use.

[`instance:rename INSTANCE --domain=DOMAIN`](/cli/instance/rename) changes the domain of an active development checkout's own single-target Project Route. The API is `POST /api/v1/instances/{instance}/rename` with `{"domain":"login-redirect.orbit-website.test"}`. It can also record a branch already checked out by supplying `branch` in the same request. This operation does not rename the Instance, move its checkout, or change its placement.

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

An Instance removal (`instance:destroy`) waits no grace: it removes the name for good, and a client that still holds a cached answer reaches the Node for up to 30 seconds after the site is gone, the same as after the wait. `route:destroy` keeps the wait until its own flow is measured.

The grace period covers clients whose resolver honors the TTL, such as systemd-resolved and dnsmasq on Orbit Nodes. Caddy finishes every request in progress on the old target and closes idle keep-alive connections, so the next request resolves the name again. A client with a longer DNS cache of its own, such as a browser or a Java runtime, can still reach the old target and get a TLS error until its cache expires. Retry the request or reload the page.

## Publish each service on its own hostname

PowerSync uses a separate hostname for each app, such as `sync.mealou.app` or `sync.hauzer.app`. Set its client endpoint to the service hostname. App Routes do not reserve `/powersync` or strip a service path prefix.

### A service owns its hostname

A separate hostname for each service replaces ADR 0231, which published a Process under a reserved path on an app Route. A separate hostname keeps the service endpoint independent of the app's Laravel routes and removes the need for path-prefix inspection, rewriting, and publication state. Reverb still uses the app domain's reserved `/app` and `/apps` paths through its [public attachment](/reference/websocket-apps#add-an-app-on-an-instance).

## Remove a Route

`route:destroy` removes a Route and its projections. The Gateway refuses an app Route whose public edge is live, and a Route in a replacement pair, with `route.reconciliation_required`. The one exception is a [replacement that never activated](#remove-a-replacement-that-never-activated).

Removal runs these steps in order: remove DNS records, withdraw the Caddy sites, converge PHP-FPM on the Node of each development target, remove the certificates, remove firewall rules, and delete the record. A failure leaves the Route as it was, because a Route marked failed would render no site and withdraw sites the run did not reach. The task records the failed step and its error code. Repeat the same command to retry. The retry runs every step again from the start.

Removal releases the domain when the task succeeds. The Node's shared runtime and Caddy service stay. `operation-route-removal` runs only for `route.destroy`; a withdrawal that keeps the Route has no kind of its own. Instance destroy and Route update withdraw sites inside their own runs.

A targeted Route can be removed only when none of its Instances is active and the Route is not `active`. It runs the same steps, so a `pending` Route that already published its sites leaves nothing live on its Nodes. A task workspace whose source resolved is an example. The Caddy and firewall steps include every target Node.

The PHP-FPM step converges each Node of a development target, right after its site leaves. It converges every pool on the Node, so a PHP-FPM problem unrelated to the Route also stops the removal; fix it and retry. A pool skipped only because its working directory is missing does not stop it, as in [Instance removal](/reference/instance-removal). A production target keeps its dedicated PHP-FPM service, and every target keeps its Instance certificate, until [Instance removal](/reference/instance-removal). The Instances stay without a Route.

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

The Caddy, PHP-FPM, certificate, and firewall steps change Nodes over SSH. When one of those Nodes does not answer, the step fails with its own error code and the Route is unchanged. Retry with `--offline` to skip the Node.

`route:destroy --offline` checks each Node before it changes anything. It skips a Node that is not `active`, or that the [reachability probe](/reference/node-provisioning#remove-a-node) finds unreachable. A Node that answers keeps every step, and a failure on it still stops the removal. The Gateway runs the other steps, deletes the Route, and records a residue for each skipped Node in the same transaction. The response lists them under `retained_on_nodes`, each with `node_id`, `node`, and the `steps` the Node still needs.

The `route-residue` artifact of [`node:converge`](/reference/node-provisioning#converge-the-orbit-footprint) finishes the removal once the Node answers. It builds Caddy and converges PHP-FPM from stored state, which has no record of the removed Route. Then it removes the Route's certificates and firewall rules by Route ID, reconciles public firewall policy on an Ingress, and deletes the residue.

A failed cleanup keeps the residue and counts the attempt. The artifact is then `skipped`, so another site's broken Caddyfile or pool never halts the fleet rollout, and the next converge tries again. Node removal deletes the residues with the Node.

The fleet rollout visits a Node only when it belongs to the [rollout set](/reference/gateway-recovery#rollout-set-and-order). On another Node, such as the Gateway's own, run `orbit node:converge NODE` yourself. `node:converge` needs an `active` Linux Node whose WireGuard address and SSH host key the Gateway manages. The residue of any other Node stays until that Node qualifies again or is removed.

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

The Gateway's Route publication hold orders writers during normal work. The Node guard is a second layer for writers that use it: withdrawal takes an exclusive lock on `/etc/caddy/.orbit-publish.lock` for at most 5 seconds, compares the composed-from SHA-256 with the live Caddyfile, then validates a restricted candidate, renames that exact inode and reloads Caddy under the same lock. The lock file is a regular file owned by root, mode `0600`; it is never replaced or removed. Closing the descriptor releases the lock, including when the process dies. No SSH round trip occurs while the lock is held. Swamp behavior names provide no exclusion guarantee.

Withdrawal composes against the full Caddyfile read from the Node, not an admission-time whole-file capture. Its existing `plan.expect` supplies that read's checksum. An absent or empty base uses the SHA-256 of empty bytes. A mismatch refuses with `caddy.composed_from_live_mismatch`, naming both `composed_from_sha256` and `live_sha256`. Lock contention refuses with `caddy.publish_busy` after 5 seconds. Both leave the live Caddyfile untouched and skip validation and reload. The native failed-step diagnostic carries the code and checksums; the Operation keeps its existing family failure code and failure path. A fresh withdrawal reads and composes again; it never retries a stale candidate.

The hold normally prevents another Route writer between withdrawal's read and apply, so checksum refusals are rare. PHP pools still publish before Caddy, and DNS and certificate cleanup follow it. The Node guard protects the Caddyfile only; a refusal does not undo earlier pool changes. Create and clone still need Caddy and shared PHP input refreshed under their publication hold.

During the minutes of one deploy, a new writer after an old writer's publication refuses stale input at the compare. An old writer ignores the lock and can overwrite a new publication, including a write between its compare and rename. The existing hold and release idle rule bound this overlap for covered Instance lifecycle kinds, which drain before Swamp load. The idle rule omits `route.*`, Node role and Cluster Router work. This release guards withdrawal only. Site publication, delta, convergence, Ingress, role and bootstrap writers remain outside the guarantee until their planned integrations use the helper; after every writer uses it, subsequent releases share this exclusion. No drain machinery is added.

Only one operation publishes private Caddy, DNS, or Metrics configuration at a time. The Gateway takes an exclusive lock on one file, `$ORBIT_HOME/.dnsmasq-projections.lock`, before it reads Route, target, Cluster, and Router state. It holds the lock through Caddy builds, DNS publication, and activation. Nested calls in one request share it. A competitor waits up to 30 seconds, or the rest of its command time when that is shorter. Then it returns HTTP 409 `app-dev.projection_busy` before it changes anything.

Route writes also hold their Nodes (decided by the orchestrator, for Nick's review, 2026-11-06). When the Gateway admits a Route write (`route:create`, `route:update`, `route:destroy`, `route:target:set`, `instance:create`, `instance:destroy`, `instance:clone`, `instance:transfer` or `instance:migrate`), it records one hold for each Node that serves the Route's site, through Caddy or PHP. The task keeps the hold until it reaches a terminal state.

A second Route write that needs a held Node is admitted with status `waiting`. It starts no Swamp run and gets no 409. It starts when every Node it needs is free, oldest first. A newer write also waits behind an older waiting one that shares a Node. Its time budget counts from its start, and an operator can cancel it while it waits.

When a waiting `instance:create`, `instance:clone` or `route:create` gains its Nodes, the Gateway renders its whole Caddyfiles again from the current site catalog and its admitted Route. It keeps the admitted Instance, Route and certificate identities. The previous writer commits its accepted sites and releases its hold in the same transaction, so the next render includes them.

A timed-out task keeps its Nodes until Swamp confirms that its run stopped, or for 15 minutes. The DNS Node is not held: its records are keyed, and the apply phase refuses a file that changed since the read.

Task provision and cancel, `instance:register` and the Cluster Router writes also publish sites, but their run does much else. They hold the Nodes of their Route-publish step only (decided by the orchestrator, for Nick's review, 2026-11-06). Before that step the run calls `operation:direct:route-hold` with its run ID. The Gateway reads the Nodes from the admitted intent and answers when it holds all of them, after at most 5 s.

The call is repeated for up to 2 minutes. Then the step fails (the Gateway answered `423 route_hold.busy`) and the Route step does not run. After the Route step the run calls `operation:direct:route-release`, which cannot fail the run. The hold has a 5-minute lease that only a repeated hold call renews, and the end of the Operation frees it too. A Route write that waits for a Node starts before a new hold on that Node is granted.

Three cases are not held yet. A task provision that runs as a child of another Operation (`tasks.prep`, a proof or an image refresh), and a cancel that a proof run starts, keep their Route steps unheld. The revert of a failed Cluster Router set (`Restore the before state`) is not held either.

Each Cluster also has a Router lock. Setting or clearing a Router holds it through validation, setup, activation, and cleanup. Cluster state and TLD changes use it too. A competitor for the same Cluster waits up to 30 seconds and then returns HTTP 409 `cluster.router_busy`. Different Clusters use separate locks.

When an operation needs several owners, it takes them in this order: Node lifecycle or role, `app-dev` source, Cluster Router, Metrics lifecycle or credential, projection, then remote host. No database transaction stays open during remote work.

## Check Routes with Doctor

Native Route operations own site, certificate, DNS, firewall and forwarding validation. Their results carry the outcome. [Doctor](/cli/doctor/doctor#what-each-family-checks) does not repeat these checks or reads related Nodes for a Route. Its role and Process checks cover current service/runtime liveness only.

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

Route and certificate dispatch takes a kind and worker identity. The placed family invocation claims and executes on the same Node that runs the worker. Before its first effect, it saves the claimed Operation ID. A retry keeps that ID and verifies a saved terminal result through the Gateway. An empty claim ends the invocation. A lost lease ends the attempt; a later dispatch may reclaim expired work.

Retained Swamp Route composition uses Node-bound children. `route.caddy_converge` takes `{reason}` and publishes the current whole-Node render through its native workflow. The bound public GET returns `input.caddy_publication` from `CaddyPublicationPlan::forNode()`. It includes the Node identity and agent pin, then either the Caddy content, version, listener addresses and serving flag, or a bounded render refusal. Only proven render or target validation failures include `stage: render|validate`; other failures cannot permit first-Node degradation.

No Swamp model holds a publication lock. The Gateway's [Node hold](#coordinate-publication) orders the Route writes on a Node, and each apply phase refuses a file that changed since its read.

Instance removal performs Route cleanup as steps in its native parent DAG. It converges shared PHP-FPM pools even when the Instance has no Route, withdraws Caddy before certificate and runtime removal, and retains every certificate still named by a stored site. Transfer retires captured source leaves and LAN rules in the same parent run, after publishing the surviving Caddy and PHP state. No `route.withdraw_instance`, `route.cleanup_instance` or `route.retire_source` child Operation is created.

Node convergence cleans offline residue as a native step in its own run. The Gateway captures retained residue whose Route row is absent. Swamp publishes the surviving Caddy and PHP state, removes exact captured leaves and firewall rules, then publishes DNS. Certificate removal uses the admitted Node user and resolved home. A refusal stops later effects. The Gateway records success and removes only captured residue rows that have not changed during execution. Private DNS residue cleanup runs only on active Nodes with an active VPN role. A separate Gateway consumes private DNS and has no listener to clean.

### Route child contracts

Route publication records child intent from stored Route targets and Node placement. Development workload preparation uses `route.workload_prepare` with `{route_id, instance_id}` on the workload Node. The Gateway binds checkout roots, inspects the shared PHP pools, selects packages from that inspection, and requires pool publication proof. A changed stored-site render refuses before a Node effect.

Production publication prepares PHP and served web roots as native steps on the admitted production Node. A pending Route with a web root supplies its application pool through the owning Route intent. The parent DAG synchronizes environment and URL changes before publication; it creates no Instance preparation child Operations.

Shared native Route publication artifacts contain only affected Caddy groups, PHP pools and DNS entries. A Caddy artifact's `unserved` flag means it contains no affected blocks. Its `had_served_groups` flag records whether the full source snapshot had served groups, including unaffected sites. If that flag is true and `/etc/caddy/Caddyfile` is missing, forward or restore refuses with `caddy.current_config_missing` before publication. A genuinely empty Node may create its first file. Marker text inside a comment does not split the global Caddy header; only a complete Orbit marker at the start of a line does.

Every PHP pool names the account's primary group by its numeric gid, so the site step that writes a pool and the delta that later withdraws it compare the same text.

The Gateway captures `target_instance_ids`, `previous_target_instance_ids`, and `removed_instance_ids` at admission. The plan retains every admitted placement in `placements` and removed target snapshots in `detached_instances`, with `instance_id`, `node_id`, and `checkout_path` captured before association changes. A target disposition retains its previous ownership proof after associations change. Recovery preserves these snapshots after a removed Instance disappears. A replacement also records `candidate_route_id`. Secret environment values remain Gateway references.

An analytics tracking host is published and withdrawn with its Instance's analytics enable and disable runs; it has no Operation kind of its own. Public Ingress keeps Caddy automatic HTTPS.

A `node.add` parent may update a generated Route domain with exactly `{route_id, domain}` when that Route records the parent Node as its generation basis. The tuple `{route_id, domain, node_id}` must match `state.enrollment_intent.route_changes`. The child Node follows normal Route admission: the first workload Node from target Instances ordered by Instance ID, then the Route Node, stored Router, or custom-proxy Node. The generation basis does not override current placement. The Node family retains the original TLD and Cluster until these children succeed.

A child `route.destroy` request uses `{route_id, offline: false}`. The shared child admission boundary calls `AdmitRouteOperationAction::destroy` when the parent requests the child. This runs the Route guards and captures its intent before the child becomes runnable. An AI takeover requests destruction after its collector health proof.

Private DNS publication inside Node and Route parents uses the captured records and requester catalog through the shared `route-dns-publish` child. Node convergence installs the serving listener before publication. Its parent owns terminal failure and task state; publication starts no separate Operation.

Each parent prepares exact `{stage, key}` child entries. Child-phase and residue result lists allow at most 4,096 entries and use the shared kit's string and whole-result limits. Opening a phase makes only those children eligible. A commit checks each child's stamped parent, admission snapshot and successful result through the shared child proof. Final proof checks the required phases and children again. The parent completion must match that recorded result.

`route:target:unset` is record-only. The Gateway clears eligible Project Route associations in one transaction and returns the Route. It starts no run and records no Operation. Active Instance and replacement guards still apply.

Target changes broadcast `route.updated` only after the admission transaction commits. A refused preparation leaves the target unchanged and emits no `route.updated` event.

Admission prepares the complete plan in its state transaction. Every child must be registered and declared before the transaction commits. A missing child or shared capability returns `operation.kind_unavailable` with no reserved Route, replacement, role or runnable Operation. Retained Instance rename callers keep the synchronous Route update executor; HTTP and CLI Route updates admit Operations.

A single target binding grants development source access when the Route is not active. It preserves the Route's lifecycle and publication state and leaves the next Caddy build to its owning operation.

A target pool stages workload sites under temporary certificate scopes before the Router selects them; [Native Route targets](/reference/native-route-targets) describes the steps. A new version of a certificate scope that a site still names installs only after the cutover, so the old site keeps a matching certificate until it leaves.

Target-set TLS probes use pinned SSH argv commands from 209. A failed workload probe stops the Router switch. After the switch, every staged workload gets its final Caddyfile before temporary certificates are deleted, including retained workloads whose final file did not change.

Cluster Router set starts one native `operation-cluster-router` run, and unset starts one `operation-cluster-router-unset` run. Admission captures the candidate, obsolete assignments, workload Nodes, edge Nodes, DNS Nodes and certificate leaves before committing intent.

Native certificate children own issuance, inspection, trust installation and activation. The retained Node residue cleanup path only removes an admitted certificate scope after Caddy withdrawal. It resolves the admitted user locally and refuses a supplied home that does not match that account. It cannot request, publish, trust or reload certificates.

Admission records each configured Node user from the database without looking up the account on that Node. The native worker resolves its account home and group before installing prerequisites or removing old certificates.

The shared `node-caddy-package` workflow installs the pinned Caddy package; official cfgmgmt `fetch` checks its SHA-256 pin. Placed steps install the role prerequisites, reconcile Router and workload firewall rules, publish certificates and Caddy, select the Router, publish private DNS and retire the old baseline after DNS expiry. Swamp runs the system commands directly. The Gateway records the result only from the terminal run; it creates no child Operations and checks no execution receipts.

It commits the new Router before publishing private DNS from that stored selection. A DNS failure after this commit recovers forward. A failed restoration before commit keeps the candidate serving until DNS restoration and the cache wait are proven. The old Router keeps serving until Swamp waits for the DNS grace period and the Gateway confirms it elapsed. Assignment rows are deleted only after their removal children succeed. A fully withdrawn candidate repeats preparation before it serves again.

An initial production web-root activation that fails withdraws DNS and Caddy before its pool and certificate cleanup. Removal also updates the URL of the next Route serving that application directory. A late Metrics failure stays on its Operation and leaves the completed Route state intact.

A web-root update publishes the new root in the same steps. The Gateway records the new root from the result. A failed update keeps the old root recorded, and its Operation records the failed step.

Route Operations request `metrics.reconcile` with an empty input only when their planned change alters the sorted public, active hostname catalog grouped by serving Ingress Node, and one active Metrics assignment has an active serving Node. Private-only changes, unchanged publication, target associations and web-root changes leave that catalog unchanged and skip Metrics. FPM monitoring selects active production Instances independently of their Route associations. The Metrics family captures its reconciliation intent when that child is requested.

Route Operations retain their admitted Instance environment owners until completion. Admission and each executor run use the same environment locks as deploy, rollback and environment synchronization. While a Route Operation is open, unrelated Instance environment changes refuse with `env.operation_busy`; only its stamped descendants may use that owner. This keeps web-root links, Route URLs and production runtime selection on the same release through publication.

### Direct dispatch

`route.caddy_converge` has one direct dispatch path. The Gateway starts the owning native workflow after intent commits. Their polled discovery entries, executor contracts and writer choices are removed. New Operations default to direct; selecting `polled` refuses admission. Rollback is a revert PR.

These kinds use the shared `swamp.direct_writers` configuration and retain their recorded writer identity. The separate publication settings and their environment variables are removed. No direct kind exposes a Gateway execution contract, even for an old unstamped row. The remaining Route and Certificate kinds retain their family discovery entries until their owning native cutover.

Complete Node Caddy builds use official cfgmgmt file application and an end check, with pinned SSH argv for validation and reload. Their native end assertion replaces the reporting shell. The Gateway records the public content version captured from the input validation in the same native history response.

Native Cluster Router admission captures Caddy recipe candidates through the same native-only producer as Node convergence. Its [candidate rollout](/reference/compute-drivers#the-recipe-artifact-mirror) accepts the schema before producing candidates, then activates consumers after that Gateway release is live.

Site rendering uses only live Instance removal members. Abandoned history does not retain the unavailable site from a previous removal.
