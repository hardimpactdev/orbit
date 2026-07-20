# Concepts

This file is a routing index for Orbit concepts. It helps humans and LLM agents
find the owning document for a term. Keep entries short; definitions and
behavior contracts live in the linked source documents.

Marked `concept-index` blocks are checked by `composer docs-lint` against the
owning family concept document.

## Global Concepts

- **Gateway configuration** — the durable state stored on the gateway. See [Architecture: State Model](architecture.md#state-model).
- **Node reality** — observed runtime state on a node. See [Architecture: State Model](architecture.md#state-model).
- **State family** — one area Orbit tracks, with gateway configuration, node reality probes, and drift handling. See [Architecture: State Families](architecture.md#state-families).
- **Drift** — a difference between gateway configuration and node reality: a config mismatch, a pending update, or a runtime problem. See [Architecture: Keeping Nodes In Sync](architecture.md#keeping-nodes-in-sync).
- **Verify** — default doctor mode that reports drift without acting. No flag. See [Architecture: Keeping Nodes In Sync](architecture.md#keeping-nodes-in-sync).
- **Restore** — doctor mode that re-applies gateway configuration on the node. Flag: `--restore`. See [Architecture: Keeping Nodes In Sync](architecture.md#keeping-nodes-in-sync).
- **Adopt** — doctor mode that records observed node reality into gateway configuration. Flag: `--adopt`. See [Architecture: Keeping Nodes In Sync](architecture.md#keeping-nodes-in-sync).
- **Fix (interactive)** — doctor mode that asks per drifted item whether to restore or adopt. Flag: `--fix`. See [Architecture: Keeping Nodes In Sync](architecture.md#keeping-nodes-in-sync).
- **VPN identity** — a node's WireGuard credentials, used by the gateway as the authentication for every API call. See [Architecture: Authentication And Authorization](architecture.md#authentication-and-authorization).
- **WireGuard service address** — the node's assigned WireGuard IP used as the stable private host for TCP service endpoints and private backend routing. Linux nodes keep a self-route so services on a node can reach their own WireGuard address. See [Node Concepts](domains/1_node/node-concepts.md).
- **Node access grant** — gateway-stored edge that lets one node operate on another after WireGuard identity is authenticated. The grant edge is the first authorization gate; the scoped permissions stored on it are the second. See [Architecture: Authentication And Authorization](architecture.md#authentication-and-authorization).
- **Node access permission** — normalized permission string stored on a node access grant; decides what the consuming node may do on the serving node. See [Node Concepts](domains/1_node/node-concepts.md).
- **Permission preset** — code-defined named bundle of node access permissions. The defined presets are `agent-self`, `operator`, `read-only`, `developer`, `admin`, and `gateway-admin`. See [Node Concepts](domains/1_node/node-concepts.md).
- **Operator** — node identity with the operator permission preset and grants to operate one or more nodes through the gateway. It is not a node role. See [Architecture: Authentication And Authorization](architecture.md#authentication-and-authorization).
- **Gateway-admin grant** — a consumer-to-gateway grant whose permissions include `*` (the `gateway-admin` preset); confers fleet-wide super-admin authority including authority over future nodes. See [Node Concepts](domains/1_node/node-concepts.md).
- **Node TLD** — mandatory node-level identity for every active Orbit node. A node holds at most one unique `tld` value at a time; `orbit` is reserved for the proxy-owned `.orbit` namespace. The node DNS projection publishes `orbit.<node-tld>` for every active node and adds wildcard records only for active `app-dev` and `agent` roles. See [Node Concepts](domains/1_node/node-concepts.md).
- **Agent role** — exclusive workload role for autonomous agent runtimes; selectable only during `node:new` and rejected by `node role:add`. See [Node Concepts](domains/1_node/node-concepts.md).
- **VPN role** — gateway-coupled infrastructure role that owns the WireGuard server runtime, public endpoint settings, and peer defaults, and requires the DNS tool capability. See [Node Concepts](domains/1_node/node-concepts.md).
- **Router role** — gateway-coupled infrastructure role that owns private `.orbit` DNS/service hostnames, private route artifacts, backend pools, and private HTTP/WebSocket/S3 routing. See [Node Concepts](domains/1_node/node-concepts.md).
- **Ingress role** — workload role that owns public production HTTP ingress, public `orbit-caddy` route artifacts, public TLS, and public edge hardening. It forwards public routes to `router` over WireGuard. See [Node Concepts](domains/1_node/node-concepts.md).
- **WebSocket role** — private workload role that runs Laravel Reverb in a Docker runtime container managed by Orbit, binds only to WireGuard, and receives traffic through router-owned private service routes. See [Node Concepts](domains/1_node/node-concepts.md).
- **S3 role** — private workload role that runs one SeaweedFS S3-compatible object storage backend in a Docker runtime container managed by Orbit, binds only to WireGuard, and receives traffic through router-owned S3 service routes. See [Node Concepts](domains/1_node/node-concepts.md).
- **Metrics role** — optional private workload role that records and starts Prometheus and Grafana process runtimes for a metrics node and node-exporter tool/process runtimes for metrics and active Ubuntu workload nodes. See [Node Concepts](domains/1_node/node-concepts.md) and [Metrics Concepts](domains/20_metrics/metrics-concepts.md).
- **Analytics role** — private workload role that runs Plausible CE, binds only to WireGuard, and receives dashboard and tracking traffic through router-owned analytics routes. See [Node Concepts](domains/1_node/node-concepts.md) and [Analytics Concepts](domains/21_analytics/analytics-concepts.md).
- **Gateway-coupled infrastructure role** — role assignment stored separately from `gateway` but coupled to it in v1, so first gateway bootstrap assigns it together with `gateway` and normal `node role:*` commands cannot manage it independently. See [Node Concepts](domains/1_node/node-concepts.md).
- **Production public HTTP traffic** — traffic that enters the fleet through an active `ingress` role. `app-prod` nodes are production runtime backends: they own project files, FrankenPHP instance runtime policy, process-backed runtime units, and a private `orbit-caddy` listener, but they do not own public route exposure unless they also carry `ingress`. See [Architecture: Node roles](architecture.md#node-roles).
- **Instance WebSocket binding** — gateway-owned instance configuration that enables one instance to use the fleet websocket service, including Reverb app credentials, allowed origins, public WebSocket hosts, and private `websocket.orbit` publishing configuration. See [Project and Instance Concepts](domains/5_project/project-concepts.md).
- **Reverb app credentials** — Reverb application id, key, and secret material owned by an instance WebSocket binding. See [Project and Instance Concepts](domains/5_project/project-concepts.md).
- **WebSocket backend pool** — router-owned ordered set of websocket role backends behind `websocket.orbit`. See [Proxy Concepts](domains/8_proxy/proxy-concepts.md).
- **S3 service endpoint** — stable router-owned private HTTPS endpoint `https://s3.orbit` for Orbit-managed S3-compatible object storage. See [S3 Concepts](domains/19_s3/s3-concepts.md).
- **SeaweedFS backend** — the SeaweedFS runtime behind the `s3` role, reached by router through the S3 backend pool. See [S3 Concepts](domains/19_s3/s3-concepts.md).
- **S3 public host** — operator-published HTTPS hostname such as `s3.example.com` that `ingress` forwards to `router` for S3 traffic. See [S3 Concepts](domains/19_s3/s3-concepts.md).
- **S3 service credentials** — service-level SeaweedFS access key and secret material stored on the `seaweedfs` tool row. See [S3 Concepts](domains/19_s3/s3-concepts.md).
- **Metrics service endpoint** — stable router-owned private HTTPS endpoint `https://metrics.orbit` for Grafana. See [Metrics Concepts](domains/20_metrics/metrics-concepts.md).
- **Metrics backend** — process-owned Prometheus, Grafana, and node-exporter runtime coordinated by the `metrics` role baseline, with node-exporter host binary capability tracked by the tool family. See [Metrics Concepts](domains/20_metrics/metrics-concepts.md).
- **Grafana admin credentials** — generated service-level Grafana admin username and password exposed through `metrics:credentials`. See [Metrics Concepts](domains/20_metrics/metrics-concepts.md).
- **Private analytics endpoint** — stable router-owned private HTTPS endpoint `https://analytics.orbit` for the Plausible dashboard and admin UI. See [Analytics Concepts](domains/21_analytics/analytics-concepts.md).
- **Instance analytics binding** — gateway-owned instance configuration that enables public tracking hosts for one instance without provisioning Plausible sites or injecting scripts. See [Analytics Concepts](domains/21_analytics/analytics-concepts.md).
- **Orbit launcher** — host `orbit` entry point. Production installs still use the native CLI binary artifact; source-mounted Docker and Incus development/E2E topologies point `/usr/local/bin/orbit` directly at `<source>/apps/cli/orbit`. Mutable node-local Orbit state lives under `~/.config/orbit`. See [Node Concepts](domains/1_node/node-concepts.md).
- **Orbit gateway image** — first-party `ghcr.io/hardimpactdev/orbit-gateway:<version>` FrankenPHP image that bundles the gateway application code and is used by both gateway Swarm services. See [Node Concepts](domains/1_node/node-concepts.md).
- **Orbit gateway service** — Swarm-managed `orbit-gateway` service that serves the typed gateway API and mounts `ORBIT_CONFIG_ROOT` for mutable gateway state. See [Node Concepts](domains/1_node/node-concepts.md).
- **Execution lane** — managed-work classification for Orbit nodes. Gateway-owned work stays in the gateway service or a controlled one-shot runner, node-local work uses Agent push through `RemoteLocalExecutor`, and client-owned bootstrap SSH is a separate pre-Agent edge rather than a gateway execution lane. See [Runtime Execution Lanes](execution-lanes.md).
- **Client bootstrap SSH** — initiating-client-to-target edge used only to observe the target and install the managed user, WireGuard, CLI, and Agent substrate before Agent readiness. The gateway authors the bundle but never receives target credentials or opens target SSH. See [Runtime Execution Lanes](execution-lanes.md).
- **RemoteGatewayRuntimeExecutor** — execution lane for gateway Laravel/artisan/PDO work that must run inside the gateway container boundary. See [Runtime Execution Lanes](execution-lanes.md).
- **RemoteLocalExecutor** — execution lane where the gateway sends a typed Agent envelope to a node and invokes the node-local Orbit CLI entry point's internal executor command for packaged node-local helper logic that needs host file access and PHP/PDO. Internal executor commands verify operation tokens through the gateway API, and nodes do not store executor token signing material. See [Runtime Execution Lanes](execution-lanes.md).
- **Local executor** — hidden internal CLI command surface used by `RemoteLocalExecutor`; it validates a gateway-issued operation token before reading or mutating node-local state and is not a normal user command surface. See [Architecture: Trust And Transport](architecture.md#trust-and-transport).
- **Operation token** — gateway-issued token attached to a recorded operation and validated by local executor commands before side effects. See [Architecture: Trust And Transport](architecture.md#trust-and-transport).
- **Orbit Caddy container** — standalone `orbit-caddy` fleet proxy container; one per node when that node needs HTTP routing. See [Node Concepts](domains/1_node/node-concepts.md).
- **App runtime container** — dedicated Docker container for one PHP project instance or workspace runtime, represented as a process-backed runtime unit. See [Project and Instance Concepts](domains/5_project/project-concepts.md).
- **FrankenPHP app runtime** — the PHP web runtime used by instance and workspace containers. Classic mode is the default; worker mode is opt-in. See [Project and Instance Concepts](domains/5_project/project-concepts.md).
- **Worker mode** — opt-in FrankenPHP mode that keeps a validated Laravel project in memory. See [Project and Instance Concepts](domains/5_project/project-concepts.md).
- **Worker config** — gateway-tracked worker settings stored separately from the enabled flag. See [Project and Instance Concepts](domains/5_project/project-concepts.md).
- **Process runtime** — backend selection for process units scoped to a node, instance, or workspace; supported runtime families are `systemd`, `launchd`, `docker`, and `docker-swarm`, with owner-scope restrictions documented in [Process Concepts](domains/7_process/process-concepts.md).
- **Docker process runtime** — Docker backend for containerized processes such as databases, caches, and FrankenPHP app or workspace web runtimes. See [Process Concepts](domains/7_process/process-concepts.md).
- **Systemd process runtime** — Linux service backend for host-command process units scoped to nodes, instances, or workspaces; `systemctl` is only the command adapter. See [Process Concepts](domains/7_process/process-concepts.md).
- **Launchd process runtime** — macOS user LaunchAgent backend for host-command process units scoped to nodes, instances, or workspaces; `launchctl` is only the command adapter. See [Process Concepts](domains/7_process/process-concepts.md).
- **Host cwd context** — entrypoint-provided `ORBIT_HOST_CWD` value used to preserve local instance/workspace context for the dispatched node-local Orbit CLI entry point. The source CLI entrypoint initializes it from the process cwd when absent and preserves supplied values. Production installs still use the native CLI binary artifact; source-mounted Docker and Incus development/E2E topologies point `/usr/local/bin/orbit` directly at `<source>/apps/cli/orbit`. See [Workspace Concepts](domains/6_workspace/workspace-concepts.md).
- **VPN role settings** — `public_endpoint`, `wireguard_cidr`, `wireguard_port`, and `dns_ip` settings stored on the `vpn` role assignment. See [Node Concepts](domains/1_node/node-concepts.md).
- **VPN-role runtime administration** — VPN command-domain exception where `vpn-client:*` and `vpn-web-ui:*` commands are authorized by the gateway and execute against the active `vpn` role runtime. See [VPN Concepts](domains/13_vpn/vpn-concepts.md).
- **Process manager** — the runtime backend that runs Orbit process units. Systemd handles Linux host-command process units, launchd handles macOS host-command process units, Docker handles containerized process units, and Docker Swarm handles selected node-owned service processes. See [Tech Stack: Process Manager](tech-stack.md#process-manager).
- **Runtime unit** — concrete runnable realization of a process definition in a node, instance, or workspace context. See [Process Concepts](domains/7_process/process-concepts.md).
- **Orbit Scheduler** — the resident schedule executor loop that runs as the `orbit-scheduler` Swarm service using the Orbit gateway image. It owns schedule evaluation, dispatch (locally for gateway-target schedules, through `internal:schedule:run` over agent-push for every other target), overlap policy, run history, and heartbeat. See [Schedule Concepts](domains/9_schedule/schedule-concepts.md).
- **Host init** — the host's own service manager. In the production substrate, its steady-state Orbit responsibility is keeping Docker available for Docker-backed artifacts, systemd available for Linux host command processes, and launchd available for macOS host command processes.
- **Node command transport envelope** — the typed executable unit sent from gateway to node: command id plus payload contract. Managed execution converges on `gateway-only` for gateway-owned work and `agent-push` for node-local execution. Agent support is explicit per envelope, allowlisted, operation-token scoped, and not arbitrary shell. See [Tech Stack: Gateway To Node](tech-stack.md#gateway-to-node) and execution lanes.
- **Security section** — cross-family doctor issue-code section for security-owned state. Security is not a state family; findings live under owning families such as `node.security.*`, `instance.security.*`, and `workspace.security.*`. See [Architecture: State Families](architecture.md#state-families).
- **CLI caller** — an Orbit CLI invocation from a client, the gateway host, or any other node. See [Architecture: Trust And Transport](architecture.md#trust-and-transport).
- **Gateway API** — typed HTTPS API served on the gateway WireGuard address. See [Tech Stack: Gateway API](tech-stack.md#gateway-api).
- **Agent IDE adapter** — Orbit's integration point for an agent IDE (PolyScope, OpenCode, or similar), configured per node with an optional per-instance override. See [Architecture: Agent IDE Integration](architecture.md#agent-ide-integration).
- **Command contract** — user-visible command behavior, input, output, and failure contract. See [Architecture: Command And API Model](architecture.md#command-and-api-model) and [Command Contracts](domains/README.md).
- **Public command ownership** — public operator commands are owned by the `apps/cli` application; gateway Artisan is gateway maintenance and internal automation only, and moved public commands are not gateway command targets. See [Architecture: CLI](architecture.md#cli).
- **E2E verification ownership** — E2E is root-owned monorepo verification run through root Composer scripts and implemented by the dedicated external `apps/e2e` black-box/gray-box runner (the harness, support layer, test suites, and `e2e:*` runner commands all live in `apps/e2e`); there is no gateway-owned E2E runner. New S3/SeaweedFS E2E coverage is added under `apps/e2e`. See [Testing](testing/README.md).
- **Database connection restore** — doctor direction that writes gateway-owned database connection values into a selected instance or workspace `.env` while preserving unrelated keys. See [Database Doctor](domains/18_database/database-doctor.md).
- **Database connection adopt** — doctor direction that reads supported database env prefixes from a selected instance or workspace `.env` and records them into gateway state. See [Database Doctor](domains/18_database/database-doctor.md).

## Product Families

Permanent state-family keys are singular product names:

- `node`
- `instance`
- `workspace`
- `process`
- `proxy`
- `schedule`
- `tool`
- `firewall_rule`
- `database_connection`

`security` is intentionally absent from this list. Security appears as an
owning-family section, not as a product family.

See [Architecture: State Families](architecture.md#state-families).

## Node Concepts

Source: [Node Concepts](domains/1_node/node-concepts.md).

<!-- concept-index:domains/1_node/node-concepts.md -->
- **Node**
- **Client**
- **Operator**
- **Node role**
- **Gateway role**
- **VPN role**
- **Router role**
- **Gateway-coupled infrastructure role**
- **Orbit launcher**
- **Orbit gateway image**
- **Orbit gateway service**
- **Orbit Caddy container**
- **WebSocket role**
- **S3 role**
- **Metrics role**
- **Analytics role**
- **Agent role**
- **Ingress role**
- **Role assignability**
- **Role assignment**
- **Role settings**
- **Node setup**
- **Node convergence**
- **Node TLD**
- **Agent role baseline**
- **Agent runtime user**
- **Role assignment status**
- **Caller identity**
- **Node identity**
- **WireGuard service address**
- **First-gateway bootstrap**
- **Client enrollment**
- **Workload bootstrap**
- **Compatible existing node**
- **CLI-to-gateway edge**
- **Gateway maintenance edge**
- **Gateway-to-node edge**
- **Node event ingestion**
- **Node reality**
- **VPN role settings**
- **Consuming node**
- **Serving node**
- **Node access permission**
- **Permission registry**
- **Permission implication**
- **Permission normalization**
- **Wildcard permission**
- **Namespace wildcard permission**
- **Permission preset**
- **Agent self preset**
- **Operator preset**
- **Read-only preset**
- **Developer preset**
- **Admin preset**
- **Gateway-admin preset**
- **Self-grant**
- **Gateway-admin grant**
- **Cross-node grant**
- **Directional grant setup**
- **Agent tool selection**
- **Multi-agent-tool warning**
- **Node DNS projection**
- **Concrete node DNS record**
- **Development wildcard DNS mapping**
- **Agent wildcard DNS mapping**
- **Node DNS materializer**
- **Node DNS projection probe**
- **`app-dev` HTTP address for callers**
<!-- /concept-index -->

## Gateway Concepts

Source: [Gateway Concepts](domains/2_gateway/gateway-concepts.md).

<!-- concept-index:domains/2_gateway/gateway-concepts.md -->
- **Gateway command domain**
- **Gateway relationship**
- **Configured gateway endpoint**
- **Active gateway**
- **Gateway WireGuard API address**
- **Gateway API runtime**
- **Local gateway configuration**
- **Gateway root CA**
- **Gateway trust material**
- **Bootstrap-safe trust path**
- **Local gateway CA trust**
- **Local trust metadata**
- **Orbit route trust**
- **Local gateway onboarding**
- **Gateway trust repair**
- **Gateway API verification**
- **Gateway onboarding convergence**
- **Gateway-domain boundaries**
<!-- /concept-index -->

## Project and Instance Concepts

Source: [Project and Instance Concepts](domains/5_project/project-concepts.md).

<!-- concept-index:domains/5_project/project-concepts.md -->
- **Project**
- **Instance**
- **Instance driver**
- **Driver config**
- **Initial instance**
- **Project identity slug**
- **Project name argument**
- **Instance selector argument**
- **Orbit instance serving node**
- **Development instance**
- **Production instance**
- **Project PHP version**
- **Project runtime kind**
- **App runtime container**
- **Development packages mount**
- **Instance runtime mount**
- **Production app runtime container**
- **Production app runtime user**
- **Production release mount boundary**
- **FrankenPHP app runtime**
- **Worker mode**
- **Worker config**
- **Required PHP extensions**
- **Instance env**
- **Instance database target**
- **Instance WebSocket binding**
- **Instance analytics binding**
- **Reverb app credentials**
- **Instance agent IDE adapter**
- **Project dependency audit posture**
- **Dependency audit manager**
- **Dependency audit status**
- **Dependency audit severity bands**
- **Instance registration**
- **Instance adoption**
- **Instance adoption flag**
- **Instance pruning**
- **Instance setup pipeline**
- **Instance setup run**
- **Instance-owned route**
- **Project and instance boundaries**
- **Setup boundary**
<!-- /concept-index -->

## Workspace Concepts

Source: [Workspace Concepts](domains/6_workspace/workspace-concepts.md).

<!-- concept-index:domains/6_workspace/workspace-concepts.md -->
- **Workspace**
- **Concrete instance ownership**
- **Workspace identity slug**
- **Workspace hostname**
- **Workspace path**
- **Workspace env**
- **Workspace lifecycle status**
- **Workspace runtime container**
- **Host cwd context**
- **Workspace PHP override**
- **Workspace PHP inheritance flag**
- **Workspace agent IDE adapter**
- **Workspace agent IDE identifier**
- **Setup step definition**
- **Setup steps phase**
- **Teardown step definition**
- **Lifecycle step environment**
- **Workspace adoption**
- **Workspace adoption flag**
- **Workspace history**
- **Workspace-owned route**
- **Workspace-family boundaries**
<!-- /concept-index -->

## Process Concepts

Source: [Process Concepts](domains/7_process/process-concepts.md).

<!-- concept-index:domains/7_process/process-concepts.md -->
- **Process definition**
- **Process identity slug**
- **Process scope**
- **Instance selector**
- **Canonical project identity**
- **Process tool dependency**
- **External macOS runtime provider**
- **Managed service**
- **PostgreSQL service process**
- **Process order**
- **Runtime unit**
- **Process runtime**
- **Docker process runtime**
- **Docker Swarm process runtime**
- **Systemd process runtime**
- **Launchd process runtime**
- **Runtime unit expansion**
- **Runtime unit filename**
- **Runtime unit environment**
- **Runtime backend artifact**
- **Restart policy**
- **Crash notification policy**
- **Process runtime selection**
- **Process event**
- **Crash event**
- **Process-family boundaries**
<!-- /concept-index -->

## Proxy Concepts

Source: [Proxy Concepts](domains/8_proxy/proxy-concepts.md).

<!-- concept-index:domains/8_proxy/proxy-concepts.md -->
- **Proxy route**
- **Route owner**
- **Route kind**
- **Project route**
- **Workspace route**
- **Internal route**
- **Custom route**
- **Redirect route**
- **Tool-owned route**
- **Instance WebSocket route**
- **Project analytics route**
- **Analytics service route**
- **WebSocket service route**
- **Public S3 route**
- **S3 service route**
- **Metrics service route**
- **Public route artifact**
- **Private router artifact**
- **Private service DNS projection**
- **Exact backend DNS record**
- **Private backend artifact**
- **Route enactment state**
- **Production enactment order**
- **Router backend pool**
- **WebSocket backend pool**
- **S3 backend pool**
- **Metrics service target**
- **Analytics backend pool**
- **Orbit-managed TLS**
- **Route leaf certificate**
- **Intermediate CA certificate**
- **TLS authority boundary**
- **Hostname compatibility material**
- **Project ingress baseline**
- **Document-root policy**
- **Proxy-family boundaries**
<!-- /concept-index -->

## Schedule Concepts

Source: [Schedule Concepts](domains/9_schedule/schedule-concepts.md).

<!-- concept-index:domains/9_schedule/schedule-concepts.md -->
- **Schedule**
- **Schedule scope**
- **Instance-scoped schedule**
- **Schedule instance selector**
- **Node-scoped schedule**
- **Orbit-scoped schedule**
- **Laravel scheduler**
- **Execution source**
- **Portable interval expression**
- **Schedule timezone**
- **Schedule configuration status**
- **Orbit Scheduler**
- **Scheduler heartbeat**
- **Schedule run**
- **Schedule lock**
- **Run-history hook**
- **Schedule-family boundaries**
- **Gateway-only scheduler invariant**
- **No node-side scheduler**
<!-- /concept-index -->

## Database Concepts

Source: [Database Concepts](domains/18_database/database-concepts.md).

<!-- concept-index:domains/18_database/database-concepts.md -->
- **Database connection**
- **Database connection target**
- **Environment prefix**
- **Database connection restore**
- **Database connection adopt**
- **Existing-target rollout adoption**
- **Managed MySQL user provisioning**
- **Database query execution**
- **SQLite locality**
- **Database-family boundaries**
- **Managed service boundary**
<!-- /concept-index -->

## S3 Concepts

Source: [S3 Concepts](domains/19_s3/s3-concepts.md).

<!-- concept-index:domains/19_s3/s3-concepts.md -->
- **S3 command domain**
- **S3 role**
- **SeaweedFS backend**
- **S3 service endpoint**
- **S3 backend pool**
- **S3 public host**
- **S3 route publication**
- **S3 service credentials**
- **S3 role data path**
- **SeaweedFS managed configuration**
- **S3-domain boundaries**
- **S3-domain exclusions**
<!-- /concept-index -->

## Metrics Concepts

Source: [Metrics Concepts](domains/20_metrics/metrics-concepts.md).

<!-- concept-index:domains/20_metrics/metrics-concepts.md -->
- **Metrics command domain**
- **Metrics role**
- **Metrics backend**
- **Metrics service endpoint**
- **Prometheus service process**
- **Grafana service process**
- **Orbit node resources dashboard**
- **node-exporter host process**
- **Exporter firewall rule**
- **Grafana admin credentials**
- **Metrics-domain boundaries**
- **Metrics-domain exclusions**

<!-- /concept-index -->

## Analytics Concepts

Source: [Analytics Concepts](domains/21_analytics/analytics-concepts.md).

<!-- concept-index:domains/21_analytics/analytics-concepts.md -->
- **Analytics role**
- **Plausible CE process**
- **Analytics backing database**
- **Analytics PostgreSQL selection**
- **Private analytics endpoint**
- **Public instance analytics host**
- **Instance analytics binding**
<!-- /concept-index -->

## Deploy Concepts

Source: [Deploy Concepts](domains/10_deploy/deploy-concepts.md).

<!-- concept-index:domains/10_deploy/deploy-concepts.md -->
- **Deploy command domain**
- **Production project deployment**
- **Deployment policy**
- **Deployment pipeline**
- **Deployment step definition**
- **Deployment step command**
- **Deployment step order**
- **Deployment step timeout**
- **Retention metadata**
- **Deployment run**
- **Deployment run context**
- **Deployment run status**
- **Deployment step execution**
- **Detached deployment run**
- **Deployment run history**
- **Deployment log**
- **Latest deployment status**
- **Deployment health**
- **Deploy-domain boundaries**
- **Cross-family invocation**
<!-- /concept-index -->

## Operation Concepts

Source: [Operation Concepts](domains/11_operation/operation-concepts.md).

<!-- concept-index:domains/11_operation/operation-concepts.md -->
- **Operation command domain**
- **Cross-family workflow**
- **Local operation command**
- **Fleet-changing operation command**
- **Local update**
- **Production local update**
- **Source-dev local update**
- **Fleet update**
- **Fleet update source**
- **Fleet update order**
- **Operation event journal**
- **Immutable update plan**
- **Update lease**
- **Update target**
- **Update step**
- **Target result**
- **Doctor orchestration**
- **Doctor scope**
- **Doctor mode**
- **Doctor verify mode**
- **Doctor interactive mode**
- **Doctor force modes**
- **Family doctor contract**
- **DNS doctor ownership**
- **Doctor issue kind**
- **Doctor action**
- **Doctor verify permission**
- **Doctor restore permission**
- **Doctor adopt permission**
- **Operator preset doctor boundary**
- **Profile URL**
- **Profile request origin**
- **Baseline profile result**
- **Toolbar enrichment**
- **Toolbar auth mode**
- **Operation-domain boundaries**
- **Operation-domain exclusions**
- **Operation activity boundary**
<!-- /concept-index -->

## Cloudflare Concepts

Source: [Cloudflare Concepts](domains/12_cf/cf-concepts.md).

<!-- concept-index:domains/12_cf/cf-concepts.md -->
- **Cloudflare command domain**
- **Cloudflare provider integration**
- **Provider administration**
- **Cloudflare API token**
- **Real Cloudflare-backed domain**
- **Cloudflare zone**
- **Provider DNS record**
- **Address record**
- **Proxied DNS record**
- **Provider DNS application**
- **Provider cache purge**
- **Cloudflare cache rule**
- **Origin Cache-Control respect**
- **Cloudflare SSL mode**
- **Strict SSL mode**
- **Full SSL mode**
- **Flexible SSL exclusion**
- **Origin certificate boundary**
- **Cloudflare-domain boundaries**
- **Cloudflare-domain exclusions**
<!-- /concept-index -->

## VPN Concepts

Source: [VPN Concepts](domains/13_vpn/vpn-concepts.md).

<!-- concept-index:domains/13_vpn/vpn-concepts.md -->
- **VPN command domain**
- **VPN-role runtime administration**
- **VPN-role execution path**
- **VPN runtime backend**
- **VPN role settings**
- **Backend TOTP code**
- **VPN client**
- **VPN client name**
- **Admin VPN client**
- **Orbit node peer**
- **Unknown VPN peer**
- **VPN client kind**
- **WireGuard client configuration**
- **VPN client enablement**
- **VPN client removal**
- **VPN web UI password**
- **Backend admin credential**
- **VPN-role credential storage**
- **VPN-domain boundaries**
<!-- /concept-index -->

## PHP Concepts

Source: [PHP Concepts](domains/14_php/php-concepts.md).

<!-- concept-index:domains/14_php/php-concepts.md -->
- **PHP runtime command domain**
- **PHP runtime selection**
- **PHP image selection**
- **Supported PHP version set**
- **Available PHP image**
- **PHP runtime catalog**
- **PHP runtime policy**
- **Gateway-tracked image facts**
- **Live image inspection**
- **PHP runtime view**
- **Project PHP runtime policy**
- **Workspace PHP runtime override**
- **Workspace PHP inheritance**
- **Effective workspace PHP version**
- **Runtime PHP binary**
- **PHP runtime container artifact**
- **PHP runtime target**
- **Partial PHP application warning**
- **PHP-domain boundaries**
<!-- /concept-index -->

## Tool Concepts

Source: [Tool Concepts](domains/3_tool/tool-concepts.md).

<!-- concept-index:domains/3_tool/tool-concepts.md -->
- **Tool**
- **Tool process dependency**
- **Tool catalog**
- **Tool definition**
- **Tool category**
- **Storage tool category**
- **Agent tool category**
- **Operator tool category**
- **Tool row**
- **Required baseline tool**
- **Installable tool**
- **Managed tool**
- **Observational tool**
- **Role baseline tool**
- **Supported operating systems**
- **Agent tool**
- **Agent tool internal route**
- **Agent tool credentials**
- **Multi-agent-tool warning**
- **Unmanaged inventory**
- **Tool-owned service endpoint**
- **Tool credentials**
- **DNS tool capability**
- **DNS base configuration**
- **DNS record projection boundary**
- **Shared DNS materializer and reload**
- **Tool-family boundaries**
<!-- /concept-index -->

## Firewall Concepts

Source: [Firewall Concepts](domains/4_firewall/firewall-concepts.md).

<!-- concept-index:domains/4_firewall/firewall-concepts.md -->
- **Firewall rule**
- **Rule name**
- **Direction**
- **Action**
- **Source**
- **Destination**
- **Port**
- **Protocol**
- **Address family**
- **Interface scope**
- **Owner**
- **Protected**
- **Reason**
- **Eligible firewall target**
- **Database-only ingress**
- **Bootstrap policy**
- **Operator preset firewall boundary**
- **Firewall-family boundaries**
<!-- /concept-index -->

## Agent IDE Concepts

Source: [Agent IDE Concepts](domains/15_agent-ide/agent-ide-concepts.md).

<!-- concept-index:domains/15_agent-ide/agent-ide-concepts.md -->
- **Agent IDE integration**
- **Agent IDE adapter**
- **Agent IDE adapter registry**
- **Agent IDE adapter registry model**
- **Agent IDE adapter choices API**
- **Active Agent IDE session**
- **Workspace discovery capability**
- **Workspace path resolution capability**
- **Node Agent IDE default**
- **Instance Agent IDE override**
- **Effective Agent IDE adapter**
- **Agent IDE input token**
- **Agent IDE message**
- **Agent IDE launcher context**
- **Agent-IDE-domain boundaries**
- **Registry boundary**
<!-- /concept-index -->

## DNS Concepts

Source: [DNS Concepts](domains/16_dns/dns-concepts.md).

<!-- concept-index:domains/16_dns/dns-concepts.md -->
- **DNS command domain**
- **Caller-local DNS administration**
- **Caller-local resolver override**
- **Local resolver state managed by Orbit**
- **Local resolver backend**
- **Supported local DNS platform**
- **Development TLD**
- **Local DNS target**
- **Resolve path**
- **Reset path**
- **Resolver refresh**
- **Local DNS entry**
- **Local resolver source**
- **Local DNS entry status**
- **Node DNS projection**
- **Private `.orbit` service name**
- **DNS tool runtime boundary**
- **Public DNS boundary**
- **DNS-domain boundaries**
<!-- /concept-index -->

## Activity Concepts

Source: [Activity Concepts](domains/17_activity/activity-concepts.md).

<!-- concept-index:domains/17_activity/activity-concepts.md -->
- **Activity command domain**
- **Gateway activity history**
- **Activity entry**
- **Type**
- **Effect**
- **Subject**
- **Causer (actor)**
- **Properties**
- **Description**
- **Channel**
- **Correlation id**
- **Correlation generation**
- **Optional CLI propagation**
- **Correlation visibility**
- **Loggable interface**
- **Loggable emission**
- **Logging failure handling**
- **Activity visibility**
- **Internal activity visibility**
- **Filter denial versus empty**
- **Activity-domain boundaries**
- **Activity is not metrics**
- **Activity is not the live state**
- **Agent activity attribution boundary**
<!-- /concept-index -->
