---
title: "Metrics role"
description: "What the metrics role runs, how to enable, inspect, and disable it, and how Grafana access is authorized."
covers:
  - apps/gateway/app/Infrastructure/Metrics/**
  - apps/gateway/app/Domain/Metrics/**
  - apps/gateway/app/Infrastructure/Nodes/Metrics/**
  - apps/gateway/app/Domain/Nodes/ManagedNodeEligibility.php
  - apps/gateway/app/Http/Controllers/Api/{MetricsController,GrafanaAccessAuthorizationController}.php
  - apps/gateway/app/Infrastructure/Processes/PrometheusProcessRuntimeStatusIndex.php
  - apps/gateway/app/Infrastructure/Caddy/Build/Sources/MetricsCaddySiteSource.php
---

# Metrics role

The `metrics` role runs Prometheus and Grafana on one Node. It collects metrics from the selected managed Nodes. Exporters and Node-agent installation require managed Linux Nodes. SSH management on a macOS Node enables tools, not these Linux services. Open the dashboards at `https://metrics.orbit`. [`metrics`](/cli/metrics) lists the commands. [Service metrics](/reference/service-metrics) adds Caddy and PHP-FPM metrics to the same role.

## What runs where

The role runs two containers on the Metrics Node and two units on each selected Node.

| Part | Where | Listens on |
| --- | --- | --- |
| `orbit-metrics-prometheus` container | The Metrics Node | `127.0.0.1:9090`, with no firewall rule |
| `orbit-metrics-grafana` container | The Metrics Node | The Node's WireGuard address, port 3000 |
| `prometheus-node-exporter` unit | Every selected Node | The Node's WireGuard address, port 9100 |
| `orbit-cadvisor` unit | Every selected Node | The Node's WireGuard address, port 9102 |

Both containers use pinned official images and host networking. Their logs use `json-file`, with three files of 10 MB each. Prometheus scrapes every target every 10 seconds and keeps samples for 7 days. It labels each target with the Orbit Node name in `node`, and the dashboards filter on that label. Grafana gets one Prometheus datasource and three dashboards: `Orbit Node Resources`, `Orbit Caddy Traffic`, and `Orbit PHP Capacity`. The last two stay empty until [service metrics](/reference/service-metrics) select a Node.

`metrics:enable` and `metrics:disable` return an accepted native Operation. Node 209 applies captured public files, container specifications and firewall rules through official cfgmgmt models. The Gateway records the role intent and projects the terminal result. It creates the Grafana password once in its encrypted settings and pushes a names-only execution copy; native SSH writes the protected password file through stdin. No CA private key leaves the Gateway. The canonical certificate workflow creates its key on the target and submits a CSR to the existing signing primitive.

The workflow uses native dependencies and resume. It validates Prometheus configuration before publishing it, applies the two containers, verifies their health once, and composes the existing exporter, Caddy and private DNS workflows. Disable withdraws publication, stops the exporters, removes the containers and configuration, and preserves named volumes and credentials unless `--purge-data --force` was requested. A failed run keeps its desired role state and native step history; recovery resumes the failed step. Nodes consumers retain their existing shared runtime material contract.

The role runs its containers and units itself. They create no `Process` records, and their packages create no `Tool` records.

## Enable and recover

Enable the role with `orbit metrics:enable <node>`. The Node must be active. The role is a singleton and can share a Node with any other role. A second enable returns `node.role_conflict`, even when the existing assignment failed.

The API returns HTTP 202 with an Operation. The CLI follows that Operation. The workflow validates a candidate Prometheus configuration, publishes public files, starts the runtime, checks health once, then publishes exporters, certificates, the Grafana firewall allow, Gateway Caddy and private DNS. Each action has its own native step. Official cfgmgmt owns package, file, image, container removal, systemd and firewall effects; pinned official SSH handles individual commands and the password stdin.

A failed native step retains its history and the desired role assignment. Resume the failed native run through the normal Operation recovery path. The workflow does not hide retries, rollback journals or recovery loops. Publication failure keeps the runtime for recovery. The Gateway projects success only after the complete native workflow succeeds; failure records the shared native error code on the assignment.

The role workflow recreates its two containers from one canonical public Docker specification. The retained Nodes runtime imports that same specification, including its ownership labels, configuration hash, host networking, mount paths and health checks. Node convergence can therefore inspect or reconcile the runtime without treating it as foreign. Exporter preference changes restart only Prometheus. A password reset uses Grafana's API and replaces no container.

### Transport boundary

Official cfgmgmt currently exposes no admitted host-key field. Those effects use its default transport and the control worker's managed known-hosts state. Official SSH actions consume the admitted pins directly. This is a separate transport limitation; the role conversion does not claim to close it.

## Relocate

Move the role with `orbit node:role:relocate <node> metrics --force`. Relocate copies missing Grafana credentials to the target. It never overwrites credentials that the target already has. It converges the target, then removes the source runtime and publication.

The target convergence points every node exporter and cAdvisor firewall rule at the target's WireGuard address. It replaces an Orbit rule only when the IPv4 source is the only difference. Any other difference fails with `metrics.exporter_firewall_ownership_drift` or `metrics.cadvisor_firewall_ownership_drift`.

When the target convergence fails, rollback removes the exporters and cAdvisor, and the source keeps its runtime. The command names the `--from` retry that finishes the move. Use `--from` when the target already holds `metrics` and leftovers remain on another Node. Adding or relocating the `gateway` role grants the Gateway access to the `vpn` and `metrics` Nodes, so this path can run.

## Exporter selection

A Node can host an exporter only when the Gateway manages it. The Node must be active, run Linux, have a WireGuard address, and have a stored SSH host fingerprint. A [task VM](/reference/compute-drivers#task-vms) Node is never eligible, because the hub filter keeps the Metrics Node from reaching it. Within that set, the Gateway reads the Node's exporter preference and its active or provisioning roles.

| Preference | Node | Result |
| --- | --- | --- |
| none | Has an active or provisioning role that takes part in Metrics | Selected |
| none | Has no such role | Not selected |
| `enabled` | Any eligible Node | Selected |
| `disabled` | Any eligible Node except the Metrics Node | Not selected |
| any | Not eligible | Not selected |
| any | The Metrics Node, when eligible | Selected |

Every role takes part in Metrics except `swamp`. Adding, converging, removing or relocating `swamp` captures no Metrics fleet, so its root skips the Metrics phases. Metrics for the swamp Node comes with the Metrics port (wave 7).

`orbit metrics:exporter:enable` and `orbit metrics:exporter:disable` return an accepted native Operation and store the desired preference. Node 209 runs the official cfgmgmt package, fetch, file, systemd and firewall models. The captured public intent fixes the target, SSH identity, unit configuration, source-scoped port rules and complete Prometheus configuration. Steps read no Gateway state. Admission refuses a second active exporter change for the same Metrics owner before writing its preference.

The workflow validates a candidate Prometheus configuration, publishes it and restarts the existing container through the official cfgmgmt container model. It verifies exporter endpoints and Prometheus readiness once. A failed run retains desired preference, public configuration and native history for server-backed resume from the failed step. It creates no container replacement, rollback journal or Grafana credential copy.

These commands store the preference. Nothing else writes one: adding or adopting a Node stores no preference. Preferences survive role changes and a disabled Metrics role. The Gateway checks eligibility before it stores an enabled preference or starts remote work.

A selected Node runs the packaged `prometheus-node-exporter` unit with the Orbit drop-in `/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf`. The drop-in binds the exporter to the WireGuard address. It sets `Restart=always` and `RestartSec=2`, because the exporter can start before WireGuard adds that address at boot. Explicit exporter changes use the official firewall model to allow the Metrics Node source address to reach the port.

Explicit Metrics role and exporter Operations capture their fleet at admission and compose native workflows. Node parents retain their shared Metrics runtime material and lower contract. Gateway Node provisioning, role baselines and Route removal perform no synchronous fleet convergence.

Node role and fleet reconciliation use the native Metrics exporter lowers. They receive captured Node and Metrics addresses, snapshot exporter state and manage packages, binaries, units and exact firewall rules under the existing host locks. Their recovery restores captured configuration, services and firewall sources. Explicit exporter preference Operations use the placed workflow above.

Exporter listeners bind to the WireGuard address. Explicit exporter changes use the trusted WireGuard membership boundary: the broader `orbit:wireguard-members` allow still admits other peers, including to FPM port 9114. Source-scoped exporter allows do not override that rule. The official firewall model neither inserts deny rules ahead of member rules nor removes older interface-specific service rules. Existing Node and role convergence retains those older rules. See [service metrics](/reference/service-metrics#private-access) for Caddy access checks.

### Exporter degradation

`orbit metrics:status` observes the runtime and reports stored degradation metadata. A selected exporter that is inactive, drifting, unknown or skipped makes `reconcile_status` degraded even when Prometheus and Grafana are healthy. Status reads perform no convergence. A fresh explicit exporter Operation captures current desired state; retained Node parents own their existing lower recovery.

## cAdvisor

The node exporter reports systemd unit state, but not the CPU and memory of each unit. So each selected Node also runs cAdvisor `v0.60.5`. Orbit verifies its SHA-256 checksum before install. cAdvisor reads cgroups, so it covers systemd and Docker Processes. `GET /api/v1/processes` reads CPU and memory from it.

cAdvisor runs as the static binary `/usr/local/bin/orbit-cadvisor` under `orbit-cadvisor.service`, with `Restart=always`. The source-scoped firewall rule allows the Metrics Node to reach port 9102. Enabling or disabling the exporter on a Node does the same to cAdvisor. Disabling removes the unit, the source-scoped rule, and the binary. Older rules tied to an interface stay under Node and role convergence ownership.

cAdvisor collects only CPU and memory. Its flags disable every other metric kind and drop Docker container labels, because an unfiltered cAdvisor adds thousands of series per Node. A Docker Process's series uses its container name, `orbit-process-{id}-{name}`. A systemd Process's series uses its unit name, `orbit-process-{id}-{name}.service`. A Process without a series reports null CPU and memory, never zero.

## Service metrics

[Service metrics](/reference/service-metrics) collects Caddy traffic and PHP-FPM capacity on selected Nodes. Explicit role and exporter workflows capture their service catalog and compose the owning native workflows. Gateway provisioning, baseline and Route actions perform no synchronous Metrics fleet mutation. Node parents retain their shared service-metrics lower until their own conversion.

## Swamp worker state

The Node exporter enables its systemd collector for `orbit-node-worker.service`. Query `node_systemd_unit_state{name="orbit-node-worker.service",state="failed"} == 1` to detect a failed worker. Doctor also reports `node.worker_failed` from its read-only Node observation. Native authentication and restart errors remain in the worker journal.

## Process runtime status

A Process list reads each Process's status from the Gateway's [view of the Node agents](/reference/node-agent#gateway-view) first. For a Process whose Node has no fresh view, it reads Prometheus in one query.

In Prometheus, a systemd Process takes its state from `node_systemd_unit_state`. A missing unit is `inactive`. cAdvisor reports only running containers, so a Docker Process with a series is `running` and one without is `exited`. The Gateway caches this answer for 10 seconds.

These statuses describe sampled runtime state, not health. A systemd Process can appear `active` between crashes. [Doctor](/cli/doctor#what-each-family-checks) reports `process.crash_loop` from the unit's auto-restart sub-state or an increasing restart count when the Process is desired running; the [Processes reference](/reference/processes-and-schedules#process-runtime-state) owns that rule. Doctor does not need the Metrics role to inspect this evidence on the Node.

After the Gateway starts, stops, or restarts a Process, it reads the new status from the Node and broadcasts `process.status`. Lists show that status for 30 seconds, ahead of the view and Prometheus. When Prometheus cannot answer, the list asks each remaining Node over SSH and does not cache the answer.

## Grafana access

Open Grafana at `https://metrics.orbit` from the active Gateway Node, or from an active WireGuard peer with an access grant to the Gateway Node. A grant to only the Metrics Node is not enough. Grafana then asks for its own login.

Private DNS answers `metrics.orbit` with the Gateway's WireGuard address. The Gateway's Caddy presents an Orbit CA certificate. Before each request, Caddy calls `GET /api/v1/metrics/grafana/authorize` with the connection address. It ignores forwarding and identity headers from the caller. Caddy runs that check in the current Gateway release: it resolves the stable Gateway application path for each request, as the Gateway site does ([Runtime handoff](/reference/gateway-recovery#runtime-handoff)). It proxies admitted traffic over WireGuard to Grafana on the Metrics Node.

The Gateway refuses an unknown, inactive, ungranted, or public caller. It also refuses when it cannot establish the caller's identity or authority. When a Node or an access grant to the Gateway Node is removed while a Metrics role exists, the Gateway calls `caddy.reload` through the local agent adapter. The agent holds the shared Caddy publication lock during the reload. Later requests fail, and open streaming connections close. A failed reload reports `metrics.caddy_publication_failed`.

The Gateway's own origin also serves Grafana under `/grafana/`, behind the same check. The web app reads Node metrics there. On the Metrics Node, the rules `orbit:metrics-grafana-upstream` and `orbit:metrics-grafana-isolation` admit only the Gateway to port 3000. This applies also when the Gateway and Metrics roles share a Node. The `metrics.publication-firewall` command owns this boundary under the Node firewall lock. It checks both rules before mutation and places the allow before the deny, with both before broad WireGuard trust. If the allow fails, the deny stays in place. Removal verifies absence; abandonment without a Gateway matches only the exact Orbit comments.

## Authorization

Every Metrics route, reads included, needs exactly one active Gateway Node. So does every `node:role:add` and `node:role:remove` call for `metrics`. The Gateway authorizes the caller against the Gateway Node. The Gateway Node itself passes. Every other caller needs an access grant to the Gateway Node, or it gets `node_access.required` (HTTP 403). A grant to only the Metrics Node or an exporter Node is not enough.

With no active Gateway, or more than one, every caller gets `node_access.required` from these routes.

## Credentials

The Grafana user is `admin`. The first convergence generates a random password. The Gateway stores it encrypted in the Metrics Node's settings. Later convergence reuses it.

`orbit metrics:credentials` reads the Gateway's stored administrator credential without executing a host action. `--reset` admits one native Operation. The Gateway creates or reuses an encrypted pending password and supplies a names-only execution copy. On 209, official pinned SSH checks the candidate, applies it only if needed, and verifies it in separate steps. Terminal success promotes the pending password; failure preserves it. The CLI follows the Operation before reading the stored credential. After a failed or uncertain reset, a new `metrics:credentials --reset` reuses the pending password and checks it before another write. A direct native resume does not reopen an already-terminal Gateway Operation.

Short database transactions protect credential creation, pending reuse, promotion and purge. The Gateway holds no execution-lifetime lock and performs no host verification during a read. Native workflow steps own the execution. Credential responses use `Cache-Control: no-store`; Operation results contain public reset metadata only.

### Material for native parents

An admitted Node parent reads existing fleet material through `MetricsFleetRuntimeMaterial::resolveFleetMaterial(plan, ownerNodeId, candidate?)`. It validates the complete public plan digest, real Metrics assignment and owner address before reading the stored password. An explicit matching enrollment candidate permits capture before that Node becomes active. The lower creates no password, Operation, endpoint or host effect. It returns the existing `OperationSecretValue`; debug and JSON serialization redact its contents.

The private value has `runtime: {operation: 'converge', assignment, configuration}` and `verify: {operation: 'verify', password}`. The configuration includes the admitted public files and the existing Grafana password file. A parent captures this value under `metrics_runtime`, beside its role-specific `runtime_material`. The protected fields are `metrics_runtime.runtime.configuration` and `metrics_runtime.verify.password`. The parent owns their binding to official SSH stdin and their redaction. This data API does not activate a native parent or grant execution authority.

## Disable and purge

`orbit metrics:disable --force` returns an accepted native Operation. Force is required for removal. The workflow withdraws exporter and service monitoring, Gateway Caddy, private DNS and the published certificate. It stops the disabled runtime units, removes both containers, deletes their unit files and `/etc/orbit/metrics`, and removes its source-scoped Grafana allow. Older interface-specific rules stay under Nodes convergence ownership.

The role assignment is removed only on terminal success. Failure keeps it and the credentials for native recovery. A single active Gateway and all captured SSH targets must be available at admission; this command does not report successful partial publication cleanup.

By default, removal keeps both named data volumes, the stored Grafana password, Docker, installed packages and exporter preferences. `--purge-data --force` also removes the two exact named volumes through Docker and purges stored active and pending passwords only after native success. Docker refuses volume removal while a container still uses it. Removal has no private rollback journal.

The disabled runtime units have no boot target or restart policy. Docker owns reboot behavior while a container exists. Removing a container through retained Nodes paths leaves no enabled unit that can recreate it.

Generic Node removal and relocation retain their separately owned workflows and recovery contracts. Their offline disposition does not prove that this role workflow cleaned an unreachable host.

## Read Node metrics

[`orbit node:metrics`](/cli/node#orbit-nodemetrics) and the [web app](/reference/web-app) read CPU, memory, swap, load, uptime, pressure, and disk from the role's Prometheus. The Gateway reads through Grafana's datasource proxy with the stored Grafana credential. It runs four instant PromQL queries for the Node's exporter. Each rate uses a 40-second window. This window survives a dropped scrape and still shows a spike.

A reading is at most 10 seconds old. The web app refreshes node metrics every 10 seconds through the Gateway's `/grafana/` path. A Node without an active exporter or without samples answers `node.metrics_unreachable` (HTTP 502). Without a Metrics assignment, the read fails with `metrics.assignment_missing` (HTTP 409).

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One Metrics Node with plain Docker containers

One Node keeps placement and private publication simple. Standalone containers keep development and upgrades simple. Do not add Docker Swarm, Compose, custom images, or a registry for this role. The cost is no high availability and no rolling Metrics updates.

### One canonical runtime specification

The official container model cannot express the retained multi-argument Prometheus command, ownership labels and health checks. The role therefore renders the shared Docker argv into systemd oneshot units and applies them through official cfgmgmt. Its container model still owns removal, and its image model owns image availability. This keeps one specification for Nodes and role Operations without adding a transport or supervisor.

### cAdvisor as a binary

Exporter Nodes, such as the Gateway Node, often have no Docker. So cAdvisor runs as a static binary, not as a container.

### The Gateway Node as the authority

The Gateway owns the Metrics boundary. Authorization against the Gateway Node gives one stable authority, even when Metrics runs on another Node. A grant on the Metrics Node would split that authority.

### A Gateway check in front of Grafana

WireGuard membership alone does not prove Gateway authority. A Grafana login alone does not prove Orbit authorization. So the Gateway checks each request, and Grafana keeps its own login as a second gate. Direct Grafana access for every peer is a rejected alternative.

### Exporters only on managed Nodes

A Node without Gateway SSH management has no service-management contract with the Gateway. An exporter preference must not turn such a Node into a managed Node. A roleless Linux Node that the Gateway manages can still host an exporter. A managed Mac cannot host the Linux exporter in this slice. Enabling an exporter or assigning the Metrics role on macOS returns `metrics.platform_unsupported` (HTTP 422) before SSH.

### The credential in Metrics settings

Metrics keeps its own encrypted settings. Orbit has no generic credential system. A fresh reset reuses the pending password after failure. Only a successful native verification promotes it; credential reads return the stored active value.

### Removal that keeps data

Volumes and the password survive a removal, so re-enabling is safe. Data deletion needs explicit `--purge-data --force` intent. It addresses only the two fixed Metrics volume names.

### Node metrics through Grafana

Prometheus stays unpublished. Grafana's datasource proxy reuses the publication and the password that already exist. A new Prometheus host name is a rejected alternative. A Gateway endpoint that reads Prometheus over SSH is also rejected, because it adds an SSH round trip to each refresh.


## Shared Node runtime material

Node parents consume the retained Metrics material API and canonical runtime specification. Their existing native lower owns its execution and recovery. Metrics credential resets, role changes and exporter preference changes use their fixed native workflows. The Gateway retains state, material capture and observation; its former synchronous fleet, service, cAdvisor and exporter mutation adapters are deleted.
