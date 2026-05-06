# Concepts

This file is a routing index for Orbit concepts. It helps humans and LLM agents
find the owning document for a term. Keep entries short; definitions and
behavior contracts live in the linked source documents.

Marked `concept-index` blocks are checked by `composer docs-lint` against the
owning family concept document.

## Global Concepts

- **Gateway intent** — desired durable state stored on the gateway. See
  [Blueprint: State Model](BLUEPRINT.md#state-model).
- **Node reality** — observed runtime state on a node. See
  [Blueprint: State Model](BLUEPRINT.md#state-model).
- **State family** — product area with gateway intent, node reality probes, and
  doctor behavior. See [Blueprint: State Families](BLUEPRINT.md#state-families).
- **Drift** — a difference between gateway intent and node reality. See
  [Blueprint: Drift And Doctor](BLUEPRINT.md#drift-and-doctor).
- **Fix** — doctor mode that reapplies gateway intent to node reality. See
  [Blueprint: Drift And Doctor](BLUEPRINT.md#drift-and-doctor).
- **Adopt** — doctor mode that records compatible observed node reality into
  gateway intent. See [Blueprint: Drift And Doctor](BLUEPRINT.md#drift-and-doctor).
- **Runtime backend** — host-level supervisor that owns Orbit-managed
  long-running processes on a node. Supervisor (`supervisord`) on every
  gateway and app node. See
  [Blueprint: Runtime Backend And Orbit Scheduler](BLUEPRINT.md#runtime-backend-and-orbit-scheduler).
- **Runtime unit** — abstract product noun for an Orbit-managed long-running
  process. Rendered as a Supervisor program by the runtime backend. See
  [Process Concepts](commands/7_process/process-concepts.md).
- **Supervisor program** — backend-specific name for the rendered runtime
  unit. See [Process Concepts](commands/7_process/process-concepts.md).
- **Orbit Scheduler** — the resident schedule executor daemon (runs
  `php artisan orbit:scheduler:run`) on every gateway and app node. Owns
  schedule evaluation, due-run dispatch, overlap policy, run history, and
  heartbeat. The daemon is enacted as the `orbit_scheduler` Supervisor
  program; the program supervises the daemon, the daemon does the work.
  See [Schedule Concepts](commands/9_schedule/schedule-concepts.md).
- **Host init** — the host's own service manager that keeps the runtime
  backend alive. systemd on Ubuntu. Not the product-level process runtime.
- **RemoteShell** — gateway-to-app-node execution primitive. See
  [Building Blocks: Transport](BUILDING-BLOCKS.md#transport).
- **CLI caller** — an Orbit CLI invocation from a control node, app node, or the
  gateway host. See [Building Blocks: Transport](BUILDING-BLOCKS.md#transport).
- **Gateway API** — typed HTTPS API served on the gateway WireGuard address. See
  [Building Blocks: Gateway API Exposure](BUILDING-BLOCKS.md#gateway-api-exposure).
- **Command contract** — user-visible command behavior, input, output, and
  failure contract. See [Command Contracts](commands/README.md).

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

See [Blueprint: State Families](BLUEPRINT.md#state-families).

## Node Concepts

Source: [Node Concepts](commands/1_node/node-concepts.md).

<!-- concept-index:commands/1_node/node-concepts.md -->
- **Node**
- **Gateway**
- **Control node**
- **App node**
- **Local caller role**
- **Node identity**
- **First-gateway bootstrap**
- **Control-node enrollment**
- **Compatible existing node**
- **CLI-to-gateway edge**
- **Gateway-to-app-node edge**
- **App-node event ingestion**
- **Node reality**
- **Consuming node**
- **Serving node**
- **Gateway-owned development DNS mapping**
- **Development DNS intent model**
- **Development DNS enactor**
- **Development DNS probe**
<!-- /concept-index -->

## Gateway Concepts

Source: [Gateway Concepts](commands/2_gateway/gateway-concepts.md).

<!-- concept-index:commands/2_gateway/gateway-concepts.md -->
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

Source: [App Concepts](commands/5_app/app-concepts.md).

<!-- concept-index:commands/5_app/app-concepts.md -->
- **App**
- **App identity slug**
- **App name argument**
- **App selector argument**
- **Owning app node**
- **Development app**
- **Production app**
- **App PHP version**
- **App agent IDE adapter**
- **App registration**
- **App adoption**
- **App pruning**
- **App-owned route**
- **App-family boundaries**
<!-- /concept-index -->

## Workspace Concepts

Source: [Workspace Concepts](commands/6_workspace/workspace-concepts.md).

<!-- concept-index:commands/6_workspace/workspace-concepts.md -->
- **Workspace**
- **Workspace identity slug**
- **Workspace hostname**
- **Workspace path**
- **Workspace lifecycle status**
- **Workspace PHP override**
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

Source: [Process Concepts](commands/7_process/process-concepts.md).

<!-- concept-index:commands/7_process/process-concepts.md -->
- **Process definition**
- **Process identity slug**
- **Process order**
- **Runtime unit**
- **Runtime unit filename**
- **Runtime unit environment**
- **Supervisor program**
- **Restart policy**
- **Crash notification policy**
- **Process event**
- **Crash event**
- **Process-family boundaries**
<!-- /concept-index -->

## Proxy Concepts

Source: [Proxy Concepts](commands/8_proxy/proxy-concepts.md).

<!-- concept-index:commands/8_proxy/proxy-concepts.md -->
- **Proxy route**
- **Route owner**
- **Route kind**
- **App route**
- **Workspace route**
- **Internal route**
- **Custom route**
- **Redirect route**
- **Tool-owned route**
- **Orbit-managed TLS**
- **Hostname compatibility material**
- **App ingress baseline**
- **Document-root policy**
- **Proxy-family boundaries**
<!-- /concept-index -->

## Schedule Concepts

Source: [Schedule Concepts](commands/9_schedule/schedule-concepts.md).

<!-- concept-index:commands/9_schedule/schedule-concepts.md -->
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

## Deploy Concepts

Source: [Deploy Concepts](commands/10_deploy/deploy-concepts.md).

<!-- concept-index:commands/10_deploy/deploy-concepts.md -->
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

Source: [Operation Concepts](commands/11_operation/operation-concepts.md).

<!-- concept-index:commands/11_operation/operation-concepts.md -->
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
- **Family doctor contract**
- **Doctor issue kind**
- **Doctor action**
- **Profile target**
- **Profile request origin**
- **Baseline profile result**
- **Toolbar enrichment**
- **Toolbar auth mode**
- **Operation-domain boundaries**
<!-- /concept-index -->

## Cloudflare Concepts

Source: [Cloudflare Concepts](commands/12_cf/cf-concepts.md).

<!-- concept-index:commands/12_cf/cf-concepts.md -->
- **Cloudflare command domain**
- **Cloudflare provider integration**
- **Provider administration**
- **Cloudflare API token**
- **Real Cloudflare-backed domain**
- **Cloudflare zone**
- **Provider DNS record**
- **Address record**
- **Proxied DNS record**
- **Provider DNS enactment**
- **Provider cache purge**
- **Cloudflare cache rule**
- **Origin Cache-Control respect**
- **Cloudflare SSL mode**
- **Strict SSL mode**
- **Full SSL mode**
- **Flexible SSL exclusion**
- **Origin certificate boundary**
- **Cloudflare-domain boundaries**
<!-- /concept-index -->

## VPN Concepts

Source: [VPN Concepts](commands/13_vpn/vpn-concepts.md).

<!-- concept-index:commands/13_vpn/vpn-concepts.md -->
- **VPN command domain**
- **Gateway-local VPN administration**
- **Gateway-local execution path**
- **Gateway VPN backend**
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
- **Gateway-local credential storage**
- **VPN-domain boundaries**
<!-- /concept-index -->

## PHP Concepts

Source: [PHP Concepts](commands/14_php/php-concepts.md).

<!-- concept-index:commands/14_php/php-concepts.md -->
- **PHP runtime command domain**
- **PHP runtime selection**
- **Supported PHP version set**
- **Installed PHP runtime**
- **PHP runtime catalog**
- **Gateway-tracked installed-version facts**
- **Live installed-version inspection**
- **PHP runtime view**
- **App PHP runtime selection**
- **Workspace PHP runtime override**
- **Workspace PHP inheritance**
- **Effective workspace PHP version**
- **Node CLI PHP default**
- **PHP-FPM artifact**
- **PHP runtime target**
- **Partial PHP enactment warning**
- **PHP-domain boundaries**
<!-- /concept-index -->

## Tool Concepts

Source: [Tool Concepts](commands/3_tool/tool-concepts.md).

<!-- concept-index:commands/3_tool/tool-concepts.md -->
- **Tool**
- **Tool catalog**
- **Tool definition**
- **Tool row**
- **Required baseline tool**
- **Installable tool**
- **Managed tool**
- **Observational tool**
- **Role baseline tool**
- **Unmanaged inventory**
- **Tool-owned service endpoint**
- **Tool credentials**
- **Tool-family boundaries**
<!-- /concept-index -->

## Firewall Concepts

Source: [Firewall Concepts](commands/4_firewall/firewall-concepts.md).

<!-- concept-index:commands/4_firewall/firewall-concepts.md -->
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
- **Firewall-family boundaries**
<!-- /concept-index -->

## Agent IDE Concepts

Source: [Agent IDE Concepts](commands/15_agent-ide/agent-ide-concepts.md).

<!-- concept-index:commands/15_agent-ide/agent-ide-concepts.md -->
- **Agent IDE integration**
- **Agent IDE adapter**
- **Agent IDE adapter registry**
- **Agent IDE adapter registry model**
- **Agent IDE adapter choices API**
- **Active Agent IDE session**
- **Workspace discovery capability**
- **Node Agent IDE default**
- **App Agent IDE override**
- **Effective Agent IDE adapter**
- **Agent IDE input token**
- **Agent IDE message**
- **Agent-IDE-domain boundaries**
- **Registry boundary**
<!-- /concept-index -->

## DNS Concepts

Source: [DNS Concepts](commands/16_dns/dns-concepts.md).

<!-- concept-index:commands/16_dns/dns-concepts.md -->
- **DNS command domain**
- **Caller-local DNS administration**
- **Caller-local resolver override**
- **Orbit-managed local resolver state**
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
- **Gateway-owned development DNS mapping**
- **App-node resolver drift**
- **Public DNS boundary**
- **DNS-domain boundaries**
<!-- /concept-index -->

## Activity Concepts

Source: [Activity Concepts](commands/17_activity/activity-concepts.md).

<!-- concept-index:commands/17_activity/activity-concepts.md -->
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
<!-- /concept-index -->
