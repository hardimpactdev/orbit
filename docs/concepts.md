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
- **Node access grant** — gateway-stored edge that lets one node operate on another after WireGuard identity is authenticated. The grant edge is the first authorization gate; the scoped permissions stored on it are the second. See [Architecture: Authentication And Authorization](architecture.md#authentication-and-authorization).
- **Node access permission** — normalized permission string stored on a node access grant; decides what the consuming node may do on the serving node. See [Node Concepts](domains/1_node/node-concepts.md).
- **Permission preset** — code-defined named bundle of node access permissions. The defined presets are `agent-self`, `operator`, `read-only`, `developer`, `admin`, and `gateway-admin`. See [Node Concepts](domains/1_node/node-concepts.md).
- **Gateway-admin grant** — a consumer-to-gateway grant whose permissions include `*` (the `gateway-admin` preset); confers fleet-wide super-admin authority including authority over future nodes. See [Node Concepts](domains/1_node/node-concepts.md).
- **Node TLD** — node-level setting required by the `app-development` and `agent` roles. A node holds at most one `tld` value at a time, shared by every role that depends on it; drives the gateway-owned DNS mapping for that TLD. See [Node Concepts](domains/1_node/node-concepts.md).
- **Agent role** — exclusive workload role for autonomous agent runtimes; selectable only during `node:new` and rejected by `node role:add`. See [Node Concepts](domains/1_node/node-concepts.md).
- **VPN role** — gateway-coupled infrastructure role that owns the WireGuard server runtime, public endpoint settings, peer defaults, and VPN-facing DNS runtime. See [Node Concepts](domains/1_node/node-concepts.md).
- **Router role** — gateway-coupled infrastructure role that owns private `.orbit` DNS/service hostnames, private route artifacts, backend pools, and private HTTP/WebSocket/S3 routing. See [Node Concepts](domains/1_node/node-concepts.md).
- **Ingress role** — workload role that owns public production HTTP ingress, public `orbit-caddy` route artifacts, public TLS, and public edge hardening. It forwards public routes to `router` over WireGuard. See [Node Concepts](domains/1_node/node-concepts.md).
- **WebSocket role** — private workload role that runs Laravel Reverb in a Docker runtime container managed by Orbit, binds only to WireGuard, and receives traffic through router-owned private service routes. See [Node Concepts](domains/1_node/node-concepts.md).
- **Gateway-coupled infrastructure role** — role assignment stored separately from `gateway` but coupled to it in v1, so first gateway bootstrap assigns it together with `gateway` and normal `node role:*` commands cannot manage it independently. See [Node Concepts](domains/1_node/node-concepts.md).
- **Production public HTTP traffic** — traffic that enters the fleet through an active `ingress` role. `app-production` nodes are production runtime backends: they own app files, FrankenPHP app containers, Docker process runtime units, and a private `orbit-caddy` listener, but they do not own public route exposure unless they also carry `ingress`. See [Architecture: Node roles](architecture.md#node-roles).
- **App WebSocket binding** — gateway-owned app configuration that enables one app to use the fleet websocket service, including per-app Reverb credentials, allowed origins, public WebSocket hosts, and private `websocket.orbit` publishing configuration. See [App Concepts](domains/5_app/app-concepts.md).
- **Reverb app credentials** — per-app Reverb application id, key, and secret material owned by an app WebSocket binding. See [App Concepts](domains/5_app/app-concepts.md).
- **WebSocket backend pool** — router-owned ordered set of websocket role backends behind `websocket.orbit`. See [Proxy Concepts](domains/8_proxy/proxy-concepts.md).
- **Orbit launcher** — host `orbit` executable that runs commands inside the local `orbit-runtime` container and passes `ORBIT_HOST_CWD`. See [Node Concepts](domains/1_node/node-concepts.md).
- **Orbit runtime container** — one `orbit-runtime` container per node; it is the CLI execution target and, on the gateway, the API and scheduler runtime. See [Node Concepts](domains/1_node/node-concepts.md).
- **Orbit Caddy container** — standalone `orbit-caddy` fleet proxy container; one per node when that node needs HTTP routing. See [Node Concepts](domains/1_node/node-concepts.md).
- **App runtime container** — dedicated Docker container for one PHP app or workspace runtime. See [App Concepts](domains/5_app/app-concepts.md).
- **FrankenPHP app runtime** — the PHP web runtime used by app and workspace containers. Classic mode is the default; worker mode is opt-in. See [App Concepts](domains/5_app/app-concepts.md).
- **Worker mode** — opt-in FrankenPHP mode that keeps a validated Laravel app in memory. See [App Concepts](domains/5_app/app-concepts.md).
- **Worker config** — gateway-tracked worker settings stored separately from the enabled flag. See [App Concepts](domains/5_app/app-concepts.md).
- **Process runtime** — explicit process backend selection, currently `docker` or `supervisor`. See [Process Concepts](domains/7_process/process-concepts.md).
- **Docker process runtime** — default runtime for PHP app and workspace processes; runs as a Docker sidecar container. See [Process Concepts](domains/7_process/process-concepts.md).
- **Supervisor process runtime** — residual runtime for explicitly supported non-PHP host-side processes. See [Process Concepts](domains/7_process/process-concepts.md).
- **Host cwd context** — launcher-supplied `ORBIT_HOST_CWD` value used to preserve local app/workspace context across the container boundary. See [Workspace Concepts](domains/6_workspace/workspace-concepts.md).
- **VPN role settings** — `public_endpoint`, `wireguard_cidr`, `wireguard_port`, and `dns_ip` settings stored on the `vpn` role assignment. See [Node Concepts](domains/1_node/node-concepts.md).
- **VPN-role runtime administration** — VPN command-domain exception where `vpn-client:*` and `vpn-web-ui:*` commands are authorized by the gateway and execute against the active `vpn` role runtime. See [VPN Concepts](domains/13_vpn/vpn-concepts.md).
- **Process manager** — the runtime backend that runs Orbit process units. PHP app processes use Docker process runtime units by default; Supervisor is explicit residual scope. See [Tech Stack: Process Manager](tech-stack.md#process-manager).
- **Runtime unit** — derived runnable unit for a process definition in a specific app/workspace context. See [Process Concepts](domains/7_process/process-concepts.md).
- **Orbit Scheduler** — the resident schedule executor loop that runs inside gateway `orbit-runtime`. It owns schedule evaluation, dispatch (locally for gateway-target schedules, through `RemoteShell` for every other target), overlap policy, run history, and heartbeat. See [Schedule Concepts](domains/9_schedule/schedule-concepts.md).
- **Host init** — the host's own service manager. In the Docker-first runtime, its steady-state Orbit responsibility is keeping Docker alive.
- **RemoteShell** — gateway-to-node execution primitive. See [Tech Stack: Gateway To Node](tech-stack.md#gateway-to-node).
- **Security section** — cross-family doctor issue-code section for security-owned state. Security is not a state family; findings live under owning families such as `node.security.*`, `app.security.*`, and `workspace.security.*`. See [Architecture: State Families](architecture.md#state-families).
- **CLI caller** — an Orbit CLI invocation from a client, the gateway host, or any other node. See [Architecture: Trust And Transport](architecture.md#trust-and-transport).
- **Gateway API** — typed HTTPS API served on the gateway WireGuard address. See [Tech Stack: Gateway API](tech-stack.md#gateway-api).
- **Agent IDE adapter** — Orbit's integration point for an agent IDE (PolyScope, OpenCode, or similar), configured per node with an optional per-app override. See [Architecture: Agent IDE Integration](architecture.md#agent-ide-integration).
- **Command contract** — user-visible command behavior, input, output, and failure contract. See [Architecture: Command And API Model](architecture.md#command-and-api-model) and [Command Contracts](domains/README.md).
- **Database connection restore** — doctor direction that writes gateway-owned database connection values into a selected app or workspace `.env` while preserving unrelated keys. See [Database Doctor](domains/18_database/database-doctor.md).
- **Database connection adopt** — doctor direction that reads supported database env prefixes from a selected app or workspace `.env` and records them into gateway state. See [Database Doctor](domains/18_database/database-doctor.md).

## Product Families

Permanent state-family keys are singular product names:

- `node`
- `app`
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
- **Node role**
- **Gateway role**
- **VPN role**
- **Router role**
- **Agent role**
- **Ingress role**
- **WebSocket role**
- **Gateway-coupled infrastructure role**
- **Orbit launcher**
- **Orbit runtime container**
- **Orbit Caddy container**
- **Role assignability**
- **Role assignment**
- **Role settings**
- **VPN role settings**
- **Node TLD**
- **Agent role baseline**
- **Agent runtime user**
- **Role assignment status**
- **Caller identity**
- **Node identity**
- **First-gateway bootstrap**
- **Client enrollment**
- **Compatible existing node**
- **CLI-to-gateway edge**
- **Gateway-to-node edge**
- **Node event ingestion**
- **Node reality**
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
- **Development DNS mapping owned by the gateway**
- **Agent DNS mapping owned by the gateway**
- **Development DNS configuration model**
- **Development DNS applier**
- **Development DNS probe**
<!-- /concept-index -->

## Gateway Concepts

Source: [Gateway Concepts](domains/2_gateway/gateway-concepts.md).

<!-- concept-index:domains/2_gateway/gateway-concepts.md -->
- **Gateway command domain**
- **Gateway relationship**
- **Configured gateway endpoint**
- **Gateway WireGuard API address**
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

## App Concepts

Source: [App Concepts](domains/5_app/app-concepts.md).

<!-- concept-index:domains/5_app/app-concepts.md -->
- **App**
- **App identity slug**
- **App name argument**
- **App selector argument**
- **Owning node**
- **Development app**
- **Production app**
- **App PHP version**
- **App runtime container**
- **FrankenPHP app runtime**
- **Worker mode**
- **Worker config**
- **App WebSocket binding**
- **Reverb app credentials**
- **App agent IDE adapter**
- **App registration**
- **App adoption**
- **App pruning**
- **App-owned route**
- **App-family boundaries**
<!-- /concept-index -->

## Workspace Concepts

Source: [Workspace Concepts](domains/6_workspace/workspace-concepts.md).

<!-- concept-index:domains/6_workspace/workspace-concepts.md -->
- **Workspace**
- **Workspace identity slug**
- **Workspace hostname**
- **Workspace path**
- **Workspace lifecycle status**
- **Workspace PHP override**
- **Workspace runtime container**
- **Host cwd context**
- **Setup step definition**
- **Setup steps phase**
- **Teardown step definition**
- **Lifecycle step environment**
- **Workspace adoption**
- **Workspace history**
- **Workspace-owned route**
- **Workspace-family boundaries**
<!-- /concept-index -->

## Process Concepts

Source: [Process Concepts](domains/7_process/process-concepts.md).

<!-- concept-index:domains/7_process/process-concepts.md -->
- **Process definition**
- **Process identity slug**
- **Process order**
- **Process runtime**
- **Docker process runtime**
- **Supervisor process runtime**
- **Runtime unit**
- **Runtime unit expansion**
- **Runtime unit filename**
- **Runtime unit environment**
- **Restart policy**
- **Crash notification policy**
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
- **App route**
- **Workspace route**
- **Internal route**
- **Custom route**
- **Redirect route**
- **Tool-owned route**
- **Public route artifact**
- **Private backend artifact**
- **Private router artifact**
- **Private backend artifact**
- **Router backend pool**
- **App WebSocket route**
- **WebSocket service route**
- **WebSocket backend pool**
- **Orbit-managed TLS**
- **Route leaf certificate**
- **Intermediate CA certificate**
- **TLS authority boundary**
- **Hostname compatibility material**
- **App ingress baseline**
- **Document-root policy**
- **Proxy-family boundaries**
<!-- /concept-index -->

## Schedule Concepts

Source: [Schedule Concepts](domains/9_schedule/schedule-concepts.md).

<!-- concept-index:domains/9_schedule/schedule-concepts.md -->
- **Schedule**
- **Schedule scope**
- **App-scoped schedule**
- **Node-scoped schedule**
- **Orbit-scoped schedule**
- **Laravel scheduler**
- **Execution source**
- **Portable interval expression**
- **Orbit Scheduler**
- **Scheduler heartbeat**
- **Schedule run**
- **Schedule lock**
- **Run-history hook**
- **Schedule-family boundaries**
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
- **Database query execution**
- **SQLite locality**
- **Database-family boundaries**
<!-- /concept-index -->

## Deploy Concepts

Source: [Deploy Concepts](domains/10_deploy/deploy-concepts.md).

<!-- concept-index:domains/10_deploy/deploy-concepts.md -->
- **Deploy command domain**
- **Production app deployment**
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
- **Fleet update**
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
- **Doctor issue kind**
- **Doctor action**
- **Doctor verify permission**
- **Doctor restore permission**
- **Doctor adopt permission**
- **Operator preset doctor boundary**
- **Profile target**
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
- **Gateway-tracked image facts**
- **Live image inspection**
- **PHP runtime view**
- **App PHP runtime selection**
- **Workspace PHP runtime override**
- **Workspace PHP inheritance**
- **Effective workspace PHP version**
- **Runtime PHP binary**
- **PHP runtime target**
- **PHP runtime container artifact**
- **Partial PHP application warning**
- **PHP-domain boundaries**
<!-- /concept-index -->

## Tool Concepts

Source: [Tool Concepts](domains/3_tool/tool-concepts.md).

<!-- concept-index:domains/3_tool/tool-concepts.md -->
- **Tool**
- **Tool catalog**
- **Tool definition**
- **Tool category**
- **Agent tool category**
- **Tool row**
- **Required baseline tool**
- **Installable tool**
- **Managed tool**
- **Observational tool**
- **Role baseline tool**
- **Agent tool**
- **Agent tool internal route**
- **Agent tool credentials**
- **Multi-agent-tool warning**
- **Unmanaged inventory**
- **Tool-owned service endpoint**
- **Tool credentials**
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
- **Reason**
- **Eligible firewall target**
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
- **App Agent IDE override**
- **Effective Agent IDE adapter**
- **Agent IDE input token**
- **Agent IDE message**
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
- **Development DNS mapping owned by the gateway**
- **App-role resolver drift**
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
- **Filter denial versus empty**
- **Activity-domain boundaries**
- **Activity is not metrics**
- **Activity is not the live state**
- **Agent activity attribution boundary**
<!-- /concept-index -->
