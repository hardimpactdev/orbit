---
title: "Analytics role"
description: "How the analytics role runs Plausible Community Edition, where it keeps its data, and how an Instance publishes a tracking host."
covers:
  - apps/gateway/{app/Domain/Analytics,app/Infrastructure/Analytics,app/Actions/Analytics,extensions/analytics}/**
  - apps/gateway/app/Infrastructure/Nodes/Roles/AnalyticsRoleBaseline.php
  - apps/gateway/app/Actions/Routes/ConvergeAnalyticsTrackingPlacementAction.php
  - apps/gateway/resources/analytics/**
  - apps/gateway/tests/{Feature/Infrastructure/Analytics,Feature/Extensions/Analytics,Unit/Extensions/Analytics}/**
  - apps/cli/app/Commands/Analytics/**
  - apps/cli/app/Commands/Instances/*InstanceAnalyticsCommand.php
---

# Analytics role

The `analytics` role runs Plausible Community Edition for the fleet. An Instance sends visits to it through a tracking host. The Gateway reads the visits back for the Orbit web Instance page. [`analytics`](/cli/analytics) lists the role commands. [Instance analytics stats](/reference/instance-analytics-stats) owns the read.

Analytics currently lives in core and has no extension switch. The proposed [Analytics transition contract](/decisions/0210-give-each-extension-its-own-code-boundary#analytics-transition-contract) moves it behind an extension boundary only after explicit Process ownership and metadata-only adoption. That proposal does not enable, adopt, migrate, restart or remove anything. The operating instructions below describe the current implementation, not the proposed switch or adoption command.

The Gateway publishes and removes Analytics certificates through the shared [Node agent](/reference/node-agent#commands) Caddy commands. The agent also runs the HTTP readiness probe; the Gateway owns placement and publication intent.

## Prepare the database Processes

The role keeps its data in two Docker Processes. Create them first on an active Node with the `database` role.

| Process | Image | Holds |
| --- | --- | --- |
| PostgreSQL | `postgres:16`, or a managed `postgres:18` server | Plausible accounts and site settings |
| ClickHouse | `clickhouse/clickhouse-server`, any tag | Every tracked event |

A Process needs a command. For PostgreSQL, use `postgres`. The ClickHouse image passes its own configuration file, so give it a server argument after two dashes, such as `-- --logger.level=warning`.

Publish unmanaged PostgreSQL's container port 5432 and ClickHouse's HTTP port 8123 on the database Node's WireGuard address only. A managed PostgreSQL server may also publish its exact recorded LAN address under the [private-network trust model](/reference/database-servers#private-publication). Wildcard and public publication are refused. Analytics still connects over WireGuard.

For unmanaged storage, Plausible connects with the credentials in each Process's environment: `POSTGRES_USER` (default `postgres`) and `POSTGRES_PASSWORD`, and `CLICKHOUSE_USER`, `CLICKHOUSE_PASSWORD`, and `CLICKHOUSE_DB`. The ClickHouse container creates that database and user. Plausible connects to PostgreSQL as that Process's own user, so give analytics its own PostgreSQL Process. Plan about 2 GB of memory for the three services, and disk that grows with traffic.

For managed PostgreSQL 18, create exactly one canonical `plausible-db` connection on the server before assigning its Process to analytics. The role resolves that database owner's encrypted credentials from the connection. A missing or ambiguous owner is refused; the server admin is never used. `database:update` protects managed identity and credentials, and `database:destroy` refuses removal of the analytics owner connection. [The migration runbook](/reference/postgresql-migration#move-analytics-last) gives the required disposable Plausible rehearsal and rollback to PostgreSQL 16.

## Assign the role

Assign the role with `orbit node:role:add NODE analytics --postgres-process=ID --clickhouse-process=ID`. In a terminal, the CLI asks for a missing Process. The role is a singleton. It conflicts with `gateway` and combines with `database`, so one services Node can hold both. It cannot be assigned during `node:add`, and it cannot be relocated.

Assignment refuses a storage Process that does not fit:

| Error code | Cause |
| --- | --- |
| `analytics.postgres_process_missing`, `analytics.clickhouse_process_missing` | The Process does not exist. |
| `analytics.process_not_docker` | The Process is not a Docker Process. |
| `analytics.process_not_node` | The Process does not belong to a Node. |
| `analytics.process_not_database_node` | The Process's Node is not an active `database` Node. |
| `analytics.postgres_unsupported`, `analytics.clickhouse_unsupported` | The image is not the supported engine. |
| `analytics.storage_credentials_missing` | A required Process credential or canonical managed PostgreSQL owner connection is missing or ambiguous. |
| `analytics.storage_port_missing` | The Process does not publish port 5432 or 8123. |
| `process.wireguard_ip_missing` | The Process's Node has no WireGuard address. |

The role then converges in this order.

| Step | Result |
| --- | --- |
| Configure ClickHouse | Plausible's ClickHouse files on the ClickHouse Process's Node, and their read-only mounts in that Process. See [ClickHouse configuration](#clickhouse-configuration). |
| Admit local storage | The rule `orbit:analytics-postgres-local` or `orbit:analytics-clickhouse-local` for each storage Process on the role's own Node. Storage on another Node needs no rule. |
| Run Plausible | The `plausible` Docker Process at the pinned version, published on the Node's WireGuard address. Its environment holds the two connection URLs and a stored `SECRET_KEY_BASE`. |
| Publish the dashboard | After Plausible answers `/api/health`: an Orbit CA certificate, a Caddy site on the role's Node, and a private DNS record for `analytics.orbit`. |

The Gateway waits for Plausible with the agent's `http.await-healthy`: up to 90 requests to `/api/health` on the Node's WireGuard address, 2 seconds apart. The command takes only an `http://` or `https://` URL. It refuses any other URL with `http.url_invalid` before it runs curl.

Plausible creates and migrates its PostgreSQL database each time it starts. Its Process runs the compiled `plausible` agent service from a read-only bind of the installed static Linux agent. The service runs the image's fixed `/entrypoint.sh db createdb` and `/entrypoint.sh db migrate` steps in order, then replaces itself with `/entrypoint.sh run`. A failed step stops startup. It keeps the image's non-root account and forwards stop signals during initialization. Agent installation restarts running managed containers that use a registered compiled service and that read-only binary mount, so the next start uses the new pin. Stopped containers stay stopped.

The storage passwords, the two connection URLs, and `SECRET_KEY_BASE` reach Plausible through the Process environment. The API hides and log reads redact that environment, but the Gateway database stores it unencrypted. The `plausible` container reaches a storage Process on its own Node through the Docker bridge, not through WireGuard. That is why the local rule exists.

You can read the logs of `plausible` and restart it like any other Process. You cannot remove it, or either storage Process, while the role is assigned (`process.required_by_analytics`). The core feature registers this check as a [Process removal guard](/reference/extensions#plug-in-points).

The current lifecycle identifies Plausible by its Node and the name `plausible`, not a persisted owning-role claim. A convergence with different configuration can remove that named Process through the internal owning-role path before recreating it; role removal and unreachable forget also select by name. Do not use an unrelated Process with that name on an Analytics Node. The [ownership proposal](/decisions/0210-give-each-extension-its-own-code-boundary#explicit-process-ownership-before-extraction) replaces this unsafe selection with explicit ownership; it is not implemented by this documentation change.

`https://analytics.orbit` has no Gateway check in front of Plausible. Every WireGuard peer can reach it, because WireGuard membership is the security boundary. The first person to open it registers the Plausible owner account and owns the dashboard. Open it yourself right after you assign the role. Orbit does not create Plausible accounts or API tokens. Native Instance enable manages its site. To show visits on an Instance page, create a Stats API key in Plausible and store it with `orbit analytics:credentials --set`.

## Native Instance analytics changes

Enabling analytics records one `analytics.enable` intent and starts one native Swamp workflow. It creates or converges the Plausible site, including its domain, timezone and desired goals, then requests and publishes tracking certificates, publishes current Caddy and private DNS data on their admitted Nodes, and removes obsolete tracking hosts. Every step declares its exact Node target and matching label. The Gateway records desired Routes and projects bounded results; it does not execute these host actions or start child Operations.

Plausible management credentials use the shared private-material path. The admitted analytics Node reads the existing protected login and API key only inside the effect callback. No key enters native arguments, resources, logs or results. CE3.2.1 uses management HTTP for Sites and the site-scoped Plugins API for goals. A repeated converge preserves site history and manual goals.

Certificate requests and signed certificates are public data. The existing Gateway CA service signs only an admitted tracking hostname's public request; Node private keys remain on their Nodes. Caddy and DNS steps use the canonical per-Node publication model and fetch current data after its native lock is acquired. Their public view allows GET only. It grants no result, primitive or private-material access to a foreign Node.

An obsolete completion cannot remove a changed tracking set or a newer site binding. The owning kind selects the direct writer and removes its synchronous PHP executor in the same PR. Rollback reverts that PR. Live takeover requires exact credential approval and every admitted worker's placement proof, followed by the production canary.

When you disable analytics, the Gateway records one `analytics.disable` intent and starts one placed native workflow. It detaches the site by removing the Gateway binding after Caddy, DNS, certificates and Route withdrawal succeed. Plausible retains its site, history and manual goals. Disable needs no Plausible credential or active analytics role. It refuses to delete a changed tracking set or a newer binding, and it supports an already empty Instance. It has no PHP executor or child Operations.

## ClickHouse configuration

ClickHouse's defaults assume a large server. On a small Node, its system log tables grow and its background merges run out of memory. So each role convergence applies the four ClickHouse files that Plausible Community Edition ships, unchanged.

The role calls `analytics.configure-clickhouse` on the Node that runs the ClickHouse Process. The Rust agent carries Plausible's four files and publishes them as root with mode `0644`. It refuses symlinks, foreign ownership, and writable shared paths before publication. It writes a file only when its content differs and restores earlier files if a later publication fails. Failed restoration reports `analytics.clickhouse_recovery_failed`. The Gateway mounts each file read-only into the ClickHouse Process at the path that Plausible's own setup uses, then applies the Process restart rules below.

| Host file | Container path |
| --- | --- |
| `/etc/orbit/analytics/clickhouse/config.d/logs.xml` | `/etc/clickhouse-server/config.d/logs.xml` |
| `/etc/orbit/analytics/clickhouse/config.d/ipv4-only.xml` | `/etc/clickhouse-server/config.d/ipv4-only.xml` |
| `/etc/orbit/analytics/clickhouse/config.d/low-resources.xml` | `/etc/clickhouse-server/config.d/low-resources.xml` |
| `/etc/orbit/analytics/clickhouse/users.d/default-profile-low-resources-overrides.xml` | `/etc/clickhouse-server/users.d/default-profile-low-resources-overrides.xml` |

`logs.xml` keeps only `query_log`, for 30 days, and turns off ClickHouse's other system log tables. To diagnose ClickHouse itself, use its console log and `query_log`. Tables that ClickHouse wrote earlier keep their data. Orbit does not drop them.

The role adds only the mounts that the Process lacks. It keeps the Process's ID, name, image, command, environment, ports, and other volumes. When another volume uses one of the four container paths, convergence fails with `analytics.clickhouse_mount_conflict` before it changes anything. Remove that volume first.

| What changed | What happens to ClickHouse |
| --- | --- |
| A mount was added | The Process runtime replaces the container, which reads the files as it starts. |
| Only a file changed | The Process runtime restarts the container. A stopped Process stays stopped and reads the files at its next start. |
| Nothing | ClickHouse keeps running. |

Each ClickHouse restart drops Plausible's ClickHouse connection until ClickHouse is back.

To apply the configuration to an existing install, converge the role again with the same two Processes:

```bash
orbit node:role:add NODE analytics --converge --postgres-process=ID --clickhouse-process=ID
```

A failure stops convergence at the `clickhouse-config` step, before Plausible runs, and marks the ClickHouse Process `failed`. The next convergence repeats the step and restarts that Process. Removing the role leaves the files and mounts in place.

## Update and remove the role

`orbit analytics:update VERSION` changes the pinned Plausible version and replaces the `plausible` Process. A new role starts at the version in the Gateway's `ORBIT_ANALYTICS_PLAUSIBLE_VERSION`, which defaults to `3.2.1`.

Dashboard publication first runs the shared [Caddy package step](/reference/node-provisioning#package-sources). It leaves an installed Caddy at or above the floor in place.

`orbit node:role:remove NODE analytics` removes the `plausible` Process, the Caddy site, the certificate, the DNS record, and the role's firewall rules. It deletes the stored `SECRET_KEY_BASE` and the role settings. It never touches the two databases or their Processes. Remove those Processes yourself to remove the data. Orbit does not back up or prune Plausible's event data. Removal fails with `analytics.tracking_hosts_exist` while any Instance has a tracking host.

## Publish a tracking host

A tracking host is a Route of kind `analytics_tracking` that belongs to one Instance. It proxies only Plausible's script and event paths.

`orbit instance:analytics:enable INSTANCE` publishes `analytics.<instance domain>`. The analytics role must be active (`analytics.role_missing`). The Instance must already serve a domain (`analytics.domain_required`). `--host=HOST` names another host. You can repeat it up to ten times. The command sets the exact host set, so it removes a host that you leave out. A host that another Route owns fails with `analytics.host_taken`.

A tracking Route copies the Node, Cluster, and publication of the Instance's own Route when you create it.

| The Instance's Route | Who serves the tracking host |
| --- | --- |
| Node-scoped | The Instance's own Node, behind whatever edge fronts that Node. |
| Cluster-scoped and private | The Cluster's Router. |
| Cluster-scoped and public | The Router. The Ingress forwards the host to it, as for every public Route. |

When a Cluster attach, detach, activation, or deactivation moves the Instance, the tracking host moves with it. Both placements serve the host until private DNS answers for the old placement can have expired. Then the old placement stops. A move that fails before private DNS moves leaves the host on its old placement, and a retry moves it again.

When another edge terminates public TLS for the Instance, point the tracking host at that same edge.

The host answers two paths and nothing else:

| Path | Goes to |
| --- | --- |
| `/js/*` | The Plausible tracking script |
| `/api/event` | The Plausible event endpoint |
| Every other path | 404, so the dashboard never becomes public |

The serving Node reaches Plausible over WireGuard. While the analytics role converges, the host keeps its Caddy site and its private DNS record. A tracking Route has no target and no upstream of its own. `route:update`, `route:target:set`, and `route:target:unset` refuse it with `route.kind_unsupported`, and `route:create` cannot create one. `route:destroy` refuses direct removal with `route.tracking_managed`; disable analytics on the Instance instead.

`orbit instance:analytics:show INSTANCE` returns each host with its Route, script URL, event URL, and the DNS record to create. The Gateway knows no public address. So the record is a `CNAME` from the tracking host to the Instance's own domain. The answer also carries the script tag for the first host. Its `data-domain` is the Instance's own domain. `orbit instance:analytics:disable INSTANCE` returns an Operation that removes the hosts. Enabling creates or converges the Plausible site. Add the script tag to the Project.

An Instance with a tracking host cannot be removed (`analytics.tracking_hosts_exist`). Disable its analytics first.

## Instance page panel

The Orbit web Instance page shows live visitors, visitors for Plausible's day, 7 days, and 30 days, and the top ten pages. It shows the panel when the analytics role is active and the Instance has a tracking host. The Plausible site is the Instance's own domain. When the Gateway cannot read the Stats API, the panel says so and shows no counts. See [Instance analytics stats](/reference/instance-analytics-stats).

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Storage on database Nodes

Database servers belong on `database` Nodes, where the operator sizes, inspects, and backs them up. So the role names two Processes that the operator created, by ID. A Node can run several PostgreSQL Processes, so naming only a Node would be ambiguous. The role never creates a database server itself.

### No database provisioners

Plausible creates and migrates its own PostgreSQL database. The ClickHouse image creates its own database and user from its environment. So Orbit adds no PostgreSQL or ClickHouse administration.

### Plausible as a Process

The Process runtime already converges a Docker container on a Node, with logs, restarts, and Doctor checks. A second container runtime, Compose, or an Instance would duplicate that work.

### Plausible's own ClickHouse files

Without them, ClickHouse ran a merge loop on a small Node. Orbit uses Plausible's tested files unchanged, instead of its own variant. It mounts single files, because the ClickHouse image keeps its own files in `config.d` and `users.d`.

### A dedicated Route kind with two paths

Browsers must reach Plausible's script and event endpoints from the public internet, but they do not need the dashboard or the rest of its API. A dedicated Route kind derives its upstream from the active analytics role and exposes only `/js/*` and `/api/event`. Every other path returns 404. This fixed surface is small to verify and keeps the dashboard private.

General path proxying on any Route is rejected, because nothing else needs it and it would broaden the [custom proxy upstream rules](/reference/routes#custom-proxy-routes). A reserved prefix on the Instance's own site is also rejected, because it puts fleet infrastructure inside every Project's site, can collide with the Project's paths, and makes tracking depend on the Instance's runtime being awake. Publishing `analytics.orbit` is rejected, because it would expose the whole fleet's dashboard and login.

### A tracking host that mirrors the Instance's Route

The fleet does not have to own its public edge. A tracking host mirrors the Instance's authoritative Route, including its scope and publication, so it works behind a CDN or another proxy without changing how the Instance is served. Always making tracking hosts public and Cluster-scoped is rejected, because it would demand an Ingress and a Cluster that the fleet does not otherwise need. A host under the Instance's own domain also keeps requests first-party, so content blockers that list Plausible's domains do not drop them.

### Swamp manages the Plausible site

Enable records one parent intent and starts one native Swamp workflow. The placed jobs converge the site domain, timezone, and requested goals, then publish certificates, Caddy and private DNS on their admitted Nodes. The Gateway stores Route intent and projects observations. It never starts child Operations or relays host commands. The Project owns its markup.

Plausible CE 3.2.1 does not compile the public Sites API. The workflow uses its authenticated management HTTP endpoints and the site-scoped Plugins API for goals. Credentials come from the Gateway's protected settings through the shared exact-Node private-material endpoint. They stay inside its effect callback. Goals require a protected Plugins token for the admitted site. A missing or foreign token refuses the change before site mutation. Existing manual goals remain.

The enable API returns a queued Operation. It accepts `hosts`, an IANA `timezone` (default `Europe/Amsterdam`), and `goals`. Converging the same intent is idempotent. Host publication fetches fresh whole-Node data inside the canonical native publication lock. Every step has the admitted Node target and matching Node label.

### Stats read on the Gateway

The browser never holds the Stats API key, and it cannot ask for another Instance's site. One Gateway-scoped key serves every Instance, because Plausible scopes each read by site domain. A failed read never shows zeros, because a missing key must not look like an empty site.
