---
title: "ADR 0212: Turn ProxyCli into the AI router extension"
sidebarTitle: "0212 AI router"
description: "In progress. ProxyCli becomes the ai-router extension with a singleton ai-router role, an Orbit-managed router Process, and a cliproxyapi driver. Tasks requires it and reaches it through a core model key interface. ADR 0214 adds router upgrades."
---

# ADR 0212: Turn ProxyCli into the AI router extension

The `proxycli` extension becomes the `ai-router` extension, and every surface takes the new name with no alias. CLIProxyAPI becomes its `cliproxyapi` driver. A new singleton `ai-router` Node role places an Orbit-managed router Process and its quota collector. Tasks requires the AI router, reaches it through a small core interface, and the AI router cannot be disabled while Tasks is enabled. [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) builds on this record: it pins the router version, flags a newer upstream release, and adds `ai-router:update`.

## Status

In progress.

Principle: [One way, one name](/mission#principles). Task agents get models through one route, and that route has one name in the CLI, API, MCP, database, and documentation. It also serves [No exceptions and no legacy](/mission#principles): no `proxycli` alias stays, and Orbit refuses Tasks without the router. Exception, during the build only: step 4 of [Build order](#build-order) keeps the stored router URL as the task VM origin until the operator assigns the `ai-router` role, so live task VMs keep their keys. Step 5 removes it.

## Context

The [proxycli extension](/reference/proxycli) collects CLIProxyAPI quota into Valkey and publishes it at `collector.cli-proxy-api.orbit`. CLIProxyAPI itself is not managed by Orbit. On this fleet it is the systemd Process `cli-proxy-api` (ID 103) on the `services` Node, which an operator created by hand. It runs `~orbit/.local/share/cliproxyapi/current/cli-proxy-api` with `~orbit/.config/cliproxyapi/config.yaml`.

[Tasks](/reference/tasks) depends on CLIProxyAPI, but not on the collector. Pi on a [task VM](/reference/pi-server#run-pi-on-a-task-vm) sends every model call straight to `task_vms.model_proxy_origin`. Tasks reads neither the quota snapshot, the Valkey cache, nor the collector hostname. It uses ProxyCli only because ProxyCli stores the CLIProxyAPI management key:

- `TaskVmRuntime::prepare()` requires `ProxyCliState::cliproxyUrl()` to equal `task_vms.model_proxy_origin`. It then uses the management key to register a per-group model key and an anchor key. The anchor is stored in the setting `compute.model_proxy.anchor.<sha256(origin)>`.
- `TaskVmRuntime::release()` uses the same key to revoke the group's key at the origin stored on the task VM row.

`proxycli:teardown` deletes the management key. After a teardown, task VM preparation fails and no held key can be revoked. Nothing prevents it. This is a live hazard.

Core code names ProxyCli in many places: `DevelopmentDnsConfigRenderer` (private DNS), `ProxyCliCaddySiteSource` and the `proxycli` slug in `CaddySiteCertificates` (Caddy), `ReservedPrivateHostname`, `ProxyCliFootprintArtifact` (Node footprint), `DestroyProcessAction` through `ProxyCliProcessOwnership`, `UpdateDatabaseConnectionAction` (cache connection rules; `DestroyDatabaseConnectionAction` has no guard), the `processes` columns `proxycli_source_revision` and `proxycli_source_activation_pending`, `TaskVmRuntime`, and the wiring in `ApplicationServiceProvider`. Its surfaces are seven `proxycli-*` MCP tools, the CLI commands in `apps/cli/app/Commands/ProxyCli`, the SDK classes in `packages/php-sdk/src/{Requests,Responses}/ProxyCli`, and the web page `src/pages/Quota.tsx`.

[ADR 0210](/decisions/0210-give-each-extension-its-own-code-boundary) gives each extension its own directory, provider, and namespace. Core offers typed plug-in points through container tags, and an architecture test forbids `App\` from importing extension code. An extension declares `dependencies()`. Enabling refuses with `extension.dependency_disabled` while a dependency is off, and disabling refuses with `extension.required` while an enabled extension depends on it. ADR 0210 moves ProxyCli only after a separate plan, because Tasks depends on it. This record is that plan.

## Decision

The `ai-router` extension owns model access for agents. A singleton role places it, a driver runs its backend, and Tasks declares it as a dependency. The sections below define the names, the driver contract, the role, the plug-in points, the migration, and the build order.

### One name: the AI router

The concept is the **AI router**: the Orbit service that gives agents model access through one keyed endpoint. The extension slug, the role name, the CLI family, the MCP prefix, and the API path segment are `ai-router`. Error codes and setting keys use `ai_router`, as multiword prefixes such as `task_vm` and `database_connection` do. The PHP namespace is `Orbit\Extensions\AiRouter\`, in `apps/gateway/extensions/ai-router/`.

Every `proxycli` name moves at once. No alias, redirect command, or old error code stays.

| Old | New |
| --- | --- |
| Extension `proxycli` | Extension `ai-router` |
| `proxycli:setup` | Removed. `node:role:add NODE ai-router --cache-connection=SLUG`, then `extension:enable ai-router` |
| `proxycli:teardown` | Removed. `extension:disable ai-router` |
| `proxycli:status` | `ai-router:status` |
| `proxycli:list` | `ai-router:provider:list` |
| `proxycli:show PROVIDER` | `ai-router:provider:show PROVIDER` |
| `proxycli:models` | `ai-router:model:list` |
| `proxycli:update ACCOUNT --disabled` / `--enabled` | `ai-router:account:disable ACCOUNT` / `ai-router:account:enable ACCOUNT` |
| MCP `proxycli-status`, `-list`, `-show`, `-models`, `-update`, `-setup`, `-teardown` | `ai-router-status`, `ai-router-provider-list`, `ai-router-provider-show`, `ai-router-model-list`, `ai-router-account-enable`, `ai-router-account-disable`. `-setup` and `-teardown` are removed |
| `GET /api/v1/proxycli` | `GET /api/v1/ai-router` |
| `POST /api/v1/proxycli`, `DELETE /api/v1/proxycli` | Removed |
| `GET /api/v1/proxycli/providers`, `.../providers/{provider}` | `GET /api/v1/ai-router/providers`, `.../providers/{provider}` |
| `GET /api/v1/proxycli/models` | `GET /api/v1/ai-router/models` |
| `PATCH /api/v1/proxycli/accounts/{account}` | `POST /api/v1/ai-router/accounts/{account}/enable` and `.../disable` |
| Route names `proxycli:*` | Route names equal to the new CLI commands |
| Error codes `proxycli.*` | `ai_router.*`, such as `ai_router.cache_missing` |
| `process.required_by_proxycli` | `process.required_by_ai_router` |
| SDK `Requests/ProxyCli/*`, `Responses/ProxyCli/*`, such as `ShowProxyCliStatusRequest` | `Requests/AiRouter/*`, `Responses/AiRouter/*`, such as `ShowAiRouterStatusRequest` |
| Web section `quota` ("Quota"), `src/pages/Quota.tsx` | Web section `ai-router` ("AI router"), `src/extensions/ai-router/` |
| Hostname `collector.cli-proxy-api.orbit` | `collector.ai-router.orbit` |
| Suggested management Route `cli-proxy-api.orbit` | Suggested management Route `ai-router.orbit`, still an operator-owned custom proxy Route |
| Process `cli-proxy-api-collector` | Process `ai-router-collector` |
| Process `cli-proxy-api` (operator-made) | Process `ai-router` (Orbit-managed) |
| Collector `/var/lib/orbit/proxycli/server.py`, `resources/proxycli/server.py` | `/var/lib/orbit/ai-router/collector.py`, the `cliproxyapi` driver's resources in the extension |
| Collector environment `PROXYCLI_*` | `AI_ROUTER_*` |
| Valkey keys `orbit:proxycli:lock`, `:raw`, `:snapshot`, `:model-files`, `:backoff:<account>`, `:account:<account>` | `orbit:ai-router:lock`, `:raw`, `:snapshot`, `:model-files`, `:backoff:<account>`, `:account:<account>` |
| Footprint artifact `proxycli` | `ai-router` |
| Caddy site certificate slug `proxycli` | `ai-router` |
| Setting `extension.proxycli.enabled` | `extension.ai-router.enabled` |
| Settings `proxycli.enabled`, `proxycli.node_id`, `proxycli.cliproxy_url` | Removed. The role and the driver decide them |
| Setting `proxycli.cache_connection` | The `ai-router` role's cache connection |
| Settings `proxycli.cliproxy_management_key`, `proxycli.read_token`, `proxycli.control_token` | `ai_router.management_key`, `ai_router.read_token`, `ai_router.control_token` |
| Setting `compute.model_proxy.anchor.<sha256(origin)>` | `ai_router.anchor.<sha256(origin)>`, same hash input and value |
| None | Gateway variable `ORBIT_AI_ROUTER_VERSION`, the driver version that a new router installs |
| Config `task_vms.model_proxy_origin`, `ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN` | Removed. The origin comes from the role |
| Column `task_vms.model_proxy_origin` | `task_vms.ai_router_origin` |
| Columns `processes.proxycli_source_revision`, `processes.proxycli_source_activation_pending` | An extension-owned table `ai_router_process_sources` with the same two values |
| Pages `/reference/proxycli`, `/cli/proxycli` | `/reference/ai-router`, `/cli/ai-router`. The old paths get no redirect |

`ai-router:account:enable` and `:disable` use the toggle pair of the [command vocabulary](/reference/cli-command-vocabulary#verb-pairs). Providers and models use `list` and `show` under their own nouns, so the family keeps one family action, `status`. [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) adds the second, `update`, with its MCP tool and API operation.

CodexBar reads the collector by URL and token. The operator changes its URL to `https://collector.ai-router.orbit` once. The read token stays the same.

### Drivers

The AI router runs its backend through a **driver**, as Tasks runs agents through the `pi` driver and task VMs run on [compute drivers](/reference/compute-drivers). Orbit builds one driver, `cliproxyapi`. There is no driver selection input until a second driver exists.

A driver implements this contract. It is a PHP interface inside the extension, not a core interface. One implementation is enough reason for the seam, as the `AgentDriver` interface has only `PiDriver`: the interface gives the extension's tests a recording fake, and it keeps the backend replaceable, which is why this record makes CLIProxyAPI a driver.

| Capability | What the driver does |
| --- | --- |
| Install and run | Installs a given version on the role Node, checked against its published digest. Writes the first configuration with the stored management key. Returns the router Process specification. |
| Adopt | Checks that an existing Process runs this driver's program, and reports its installed version and configuration paths. |
| Issue and revoke keys | Registers a per-group model key and keeps one anchor key present, so the key list is never empty. Revokes a key and confirms that it no longer works. |
| Report quota and accounts | Supplies the collector program, which writes the shared snapshot of provider pools, windows, and models, and toggles one account. |
| Report the installed version | Reports the version that runs on the Node, for adoption and for [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades). |

The Process specification holds the command, user, port, and restart policy. Every driver step is idempotent. A driver never overwrites configuration that the router changes at run time, such as its key list and its account files. Today's `TaskVmModelKeys` behavior moves into the `cliproxyapi` driver unchanged. [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) adds one capability: report the latest upstream release.

The `cliproxyapi` driver keeps today's layout: versions under `~orbit/.local/share/cliproxyapi/`, the `current` link, and the configuration in `~orbit/.config/cliproxyapi/config.yaml`. The router listens on port 8317. Adoption therefore moves no file.

### Tasks requires the AI router

Pi agents on task VMs get model access only through the AI router. Orbit maintains no other model route.

- The `tasks` extension declares `ai-router` in `dependencies()`. `extension:enable tasks` refuses with `extension.dependency_disabled` while `ai-router` is off.
- The AI router may run without Tasks. Quota, accounts, and models stay useful on their own.
- While Tasks lives in `App\`, it reaches the router through one core interface, `App\Domain\TaskVms\ModelKeyIssuer`: `origin()`, `issue(origin, key)`, and `revoke(origin, key)`. The `ai-router` extension binds the implementation. Core never sees the management key or the anchor.
- `TaskVmRuntime` keeps what it owns: it creates the group key and stores the key and its origin on the task VM row before it calls `issue()`, so a retry registers the same key. `release()` calls `revoke()` with the origin stored on the row.
- The management key and the anchors stay owned by the `ai-router` extension. They do not move into core.

### Disabling is refused while it would strand a key

`extension:disable ai-router` refuses with HTTP 409:

- `extension.required` while `tasks` is enabled;
- `ai_router.keys_outstanding` while any task VM row holds a model key.

Both refusals run before the switch changes. `extension.required` is ADR 0210's own check. `ai_router.keys_outstanding` needs the pre-disable guard of [amendment A1](#required-amendments-to-adr-0210). Without it, ADR 0210 turns the switch off before the extension runs any code.

This removes today's hazard. Disabling removes the router Process, the collector Process, the collector's Caddy site and certificate, its private DNS record, and the role's firewall rules. It keeps the router's configuration and account files on the Node, and it keeps the stored management key, anchors, and tokens, so the next `extension:enable ai-router` resumes with the same accounts and CodexBar token.

### The `ai-router` role

`ai-router` is a core role in `RoleName` and `RoleRegistry`, like `analytics`. It decides where the router Process and its collector run.

This follows ADR 0210's planned order, step 4: Analytics stays core while `RoleName` is a core enum. For the same reason, the role's name and its definition below are core. Unlike Analytics, the role's behavior is extension code: assignment checks, convergence, removal, and firewall rules reach core through the [plug-in points](#plug-in-points). Core names the role only by its `RoleName` case, never by an extension class, so the boundary test still holds.

| Property | Value |
| --- | --- |
| Singleton | Yes, one per fleet |
| Assignable during `node:add` | No. Assignment needs a cache connection |
| Relocatable | No. A move changes the origin of every held key. Remove and add instead, when no key is held |
| Conflicts | `gateway` and `ingress`. See the reasons below this table |
| Node | An active Linux Node with a WireGuard address, outside `task_vms.wireguard_range` |

The router holds the credentials of every model account. So it stays off the public-facing `ingress` Node, which accepts traffic from the internet. It also stays off the `gateway` Node: that Node serves task VMs only the API on TCP 443, and a Gateway move must not move the model route.

`orbit node:role:add NODE ai-router --cache-connection=SLUG [--process=ID]`:

- `--cache-connection` is always required. The collector and its Valkey cache are part of every AI router, and there is no mode without them.
- The cache connection follows today's rules: `ai_router.cache_missing`, `ai_router.cache_invalid`, and `ai_router.cache_unplaced`.
- `--process` adopts an existing router Process on that Node. See [Migration of live state](#migration-of-live-state). It exists only to adopt Process 103. Step 4 adds it, and step 5 removes it.
- While the extension is off, the assignment places nothing. It records the Node and the cache connection, and `--process` marks the adopted Process. Adoption reads only the role, the Process, and the stored settings, never the switch.

`node:role:remove NODE ai-router` refuses with `ai_router.role_required` while the extension is enabled. Removal deletes the stored management key, anchors, and read and control tokens. It leaves the router's files on the Node, as `analytics` leaves its databases.

The task VM origin is `http://<role Node WireGuard address>:<driver port>`, for example `http://10.44.0.3:8317`. It replaces `task_vms.model_proxy_origin`.

Firewall:

| Rule | Allows |
| --- | --- |
| `orbit:ai-router-proxy` | TCP 8317 to the role Node's WireGuard address on the `orbit` interface. Task VMs and other WireGuard members reach the router. The public interface has no rule. |
| `orbit:ai-router-https` | TCP 443 on the `orbit` interface, for the collector site. |
| Hub filter | `task-vms:prepare-hub` reads the router endpoint from the role, not from `task_vms.model_proxy_origin`. Run it again after the role is first assigned. |

The Caddy guard that aborts task VM clients still covers the collector site, because only `gateway.orbit` and `reverb.orbit` admit task VMs. The router port is not a Caddy site.

### An Orbit-managed router Process

`extension:enable ai-router` refuses with `ai_router.role_missing` while no Node holds an active `ai-router` role. Otherwise its `enable()` hook converges, idempotently:

1. The router Process `ai-router` on the role Node, through the driver, at the version in `ORBIT_AI_ROUTER_VERSION`. [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) replaces that variable with a stored pin. The install uses the stored management key and generates one only when none is stored, so a re-enable after a disable keeps the same key and accounts. An adopted Process keeps its key.
2. The collector Process `ai-router-collector` on the same Node, with the cache connection of the role.
3. The collector's Orbit CA leaf, its Caddy site, and the private DNS record `collector.ai-router.orbit`.
4. The role's firewall rules.

With [amendment A2](#required-amendments-to-adr-0210), ADR 0210 runs `enable()` first and turns the switch on only after the hook succeeds. When a step fails, the switch stays off and the Gateway returns the step's error. Placed parts stay, and the next `extension:enable ai-router` reuses them. Tasks never sees a switch that is on without a router.

So the hook runs while the switch is still off, and nothing it calls reads the switch:

- It calls the extension's actions and the driver directly, never an API operation behind `RequireEnabledExtension`, which would refuse with `extension.disabled`.
- The Caddy site source, private DNS records, reserved hostname, footprint artifact, firewall rules, and Process removal guard decide from the placement record: the role assignment and the router and collector Processes it placed. They never read the switch. So the Caddy build and DNS publication inside the hook already include the collector.
- The router Process and an adopted Process 103 are Orbit-managed from the moment they are placed or adopted, so `process:destroy` refuses them also while the switch is off.
- Only the role baseline reads the switch, to place nothing on assignment while the extension is off. The hook does not go through the role baseline.

ADR 0210 keeps fleet work in a separate setup command, as `proxycli:setup` did. [Amendment A3](#required-amendments-to-adr-0210) lets an `enable()` or `disable()` hook do fleet work over SSH. The `ai-router` hook needs no input, because the role holds the Node and the cache connection, and the driver holds the rest. So one switch is the one way to start and stop the AI router.

Both Processes are Orbit-managed. `process:destroy` refuses them with `process.required_by_ai_router`. Logs, restart, start, and stop work as for any Process. Only `extension:disable ai-router` removes them.

### Plug-in points

Each point is a typed core interface that the extension tags. ADR 0210 lists five of them as points for the extensions that move after Documents. The other five are new, which ADR 0210 allows: each extension that moves adds its points the same way. For each point, the pull request that adds it deletes the hard-coded call in the last column.

| Point | In ADR 0210's list | Core caller | Hard-coded call it replaces and deletes |
| --- | --- | --- | --- |
| Caddy site source | Yes | Caddy build | `ProxyCliCaddySiteSource` in the source list in `ApplicationServiceProvider`, and the `CaddySiteCertificates::ProxyCli` slug. The source supplies its own slug `ai-router`. |
| Footprint artifact | Yes | `node:converge`, fleet rollout | `ProxyCliFootprintArtifact` in the artifact list in `FleetServiceProvider` |
| Private hostnames with optional DNS addresses | Yes | `DevelopmentDnsConfigRenderer`, `ReservedPrivateHostname` | `proxycliNode()` and its `host-record` line, and `ProxyCliHostname::Value` in `NAMES` |
| Process removal guard | Yes, as ownership guards | `DestroyProcessAction` | The direct `ProxyCliProcessOwnership::assertRemovable()` call |
| Role firewall rules | Yes, as firewall rules | `NodeFirewallRuleCatalog` | None. ProxyCli has no rules today |
| Database connection update hook | No | `UpdateDatabaseConnectionAction` | `ReconvergeProxyCliConnectionAction::validate()` and `::execute()` |
| Database connection removal guard | No | `DestroyDatabaseConnectionAction` | None. It adds the missing guard `database_connection.required_by_ai_router` |
| Role baseline and assignment check | No | `NativeRoleBaselineConverger`, `AddNodeRoleAction`, `node:role:remove` | None. The `ai-router` arm resolves the tagged `RoleBaseline` instead of naming a class. The other roles keep their arms |
| `ModelKeyIssuer` | No | `TaskVmRuntime`, `task-vms:prepare-hub` | In `TaskVmRuntime`: the `ProxyCliState` dependency, the URL check in `prepare()`, `managementKey()`, and `anchor()`. `TaskVmModelKeys` moves into the driver. In `PrepareTaskVmHubCommand`: the read of `modelProxyOrigin` |
| Process source record | No | `Process` model | The `proxycli_source_*` properties, which only `NativeProxyCliRuntimeLifecycle` uses. They move to the extension table |

The Analytics and Database server guards stay core, because their features stay core. They become tagged implementations of the same Process removal guard in the same pull request, so `DestroyProcessAction` keeps one loop and no direct calls.

`CaddyListenerRule` already places WireGuard-only sites by rule, and its comment is the only change there.

### Migration of live state

One rule governs every step: **no task VM that holds a key loses the ability to revoke it.** So every step keeps these values byte for byte: the management key, each anchor under the same origin hash, every task VM's `model_key`, and its origin. No step changes the router's key list. Any operation that would change the origin or drop the management key refuses while a task VM holds a key.

| Live state | Migration |
| --- | --- |
| `extension.proxycli.enabled` | Renamed to `extension.ai-router.enabled`. |
| `proxycli.cliproxy_management_key`, `read_token`, `control_token` | Renamed to the `ai_router.*` keys, values unchanged and still secret. |
| `compute.model_proxy.anchor.<hash>` | Renamed to `ai_router.anchor.<hash>`. The origin string keeps its normalization, so the hash and the value stay the same. |
| `proxycli.cliproxy_url` | Kept as the transitional router URL until the role is assigned, then deleted. |
| `proxycli.process_port` | Removed in step 2d. The collector port is fixed at 8787 and has no setting. |
| `proxycli.cache_connection`, `proxycli.node_id`, `proxycli.enabled` | Read once by the role assignment, then deleted. |
| `task_vms.model_proxy_origin` column | Renamed to `ai_router_origin`, values unchanged. |
| `task_vms.model_proxy_origin` config | Removed in step 5 of [Build order](#build-order). Adoption has already checked that the derived origin equals it. |
| `processes.proxycli_*` columns | Copied to `ai_router_process_sources`, then dropped. |
| Caddy certificate rows with slug `proxycli` | Renamed to `ai-router`. |
| Valkey keys | Renamed with Valkey `RENAME` while no collector runs, so each key keeps its TTL. The lock is deleted. See below the table. |
| Collector Process 163 | Stopped before the key rename, then replaced by `ai-router-collector` under the new hostname. Reads keep the renamed snapshot. Tasks does not use the collector. |
| Router Process 103 on `services` | Adopted. See below. |

The key rename covers `raw`, `snapshot`, `model-files`, every `backoff:*`, and every `account:*`. Backoff deadlines and account schedules survive, so no failing account is polled before its `Retry-After`.

Adoption of Process 103:

1. `orbit node:role:add services ai-router --cache-connection=services-valkey --process=103`.
2. The Gateway checks that the Process is a systemd Process on that Node, that the driver recognizes its program, that a management key is stored, and that the derived origin `http://10.44.0.3:8317` equals the transitional router URL and the origin of every task VM that holds a key. A mismatch refuses with `ai_router.adoption_refused` and changes nothing.
3. The Gateway marks Process 103 as the Orbit-managed router and records its installed version. It does not rewrite the unit, rename the Process, or restart it. In-flight Pi turns see no change.
4. Process 103 keeps running unchanged. The first router upgrade of [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) replaces it as `ai-router`. That is the first restart, and the operator chooses its time.

### Build order

Each step is one pull request, except step 2, which is four. Each keeps Tasks working, and each passes the acceptance test below before merge. The router upgrades of [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) come after these five steps.

1. **Core seam and hazard fix.** Add `ModelKeyIssuer`. ProxyCli implements it with today's settings and anchor key. `TaskVmRuntime` uses it and stops importing ProxyCli. `tasks` declares `proxycli` in `dependencies()`. `proxycli:teardown` refuses with HTTP 409 while Tasks is enabled or a task VM holds a key. Needs ADR 0210 merged.
2. **Plug-in points.** Add the points in the table above, except the role firewall rules and the role baseline and assignment check. Nothing can implement those two before `RoleName::AiRouter` exists, and ProxyCli has no hard-coded call there to delete, so step 4 adds them. ProxyCli contributes through tags, and core stops naming it, except in the provider list. Move the `processes.proxycli_*` columns into the extension table. Add the cache connection removal guard. No other behavior changes. Four serial pull requests do this step:
   - 2a: the Caddy site source. The ProxyCli bindings move from `ApplicationServiceProvider` into the ProxyCli provider.
   - 2b: the footprint artifact and private hostnames with optional DNS addresses.
   - 2c: the Process removal guard and the Process source record.
   - 2d: the database connection update hook and removal guard. Core calls tagged `DatabaseConnectionUpdateHook` contributions to validate the proposal before writing and reconverge after the outer transaction commits. Tagged `DatabaseConnectionRemovalGuard` contributions refuse before any drop or deletion; ProxyCli returns `database_connection.required_by_ai_router` while setup uses the connection, including while its extension switch is off. Setup and reconvergence share one collector environment builder. `ProxyCliProcess::data()` adds both the listener environment value and the Process port mapping from `ProxyCliProcess::PORT` (8787), with no override parameter. These points use trusted tagged interfaces without a runtime contribution registry.
3. **Move and rename.** Move ProxyCli into `extensions/ai-router` and rename every surface, setting, Valkey key, environment variable, hostname, certificate slug, footprint artifact, and page. `proxycli:setup` and `proxycli:teardown` become `ai-router:setup` and `ai-router:teardown` until step 4 removes them. `/reference/proxycli` and `/cli/proxycli` get no redirect. The redirects of retired ADRs 0104, 0109, 0145, and 0150, and of `/solutions/cutover-proxycli`, point at the matching sections of the new pages. The router Process is untouched. The collector restarts once under its new name and hostname. The Caddy digest walk, `SourceClosure`, learns the extension namespaces and their imports, so the moved collector site source and every class it uses stay in the Caddy footprint digest.
4. **Role, driver, and managed router.** Add the `ai-router` role, the driver contract, the `cliproxyapi` driver, adoption with `--process`, and the `enable()` and `disable()` hooks. `disable()` withdraws the collector's Caddy site, its certificate, and its private DNS record. Until this step, `extension:disable` leaves them published. Hostname reservations remain active even when the extension is off or not set up. The footprint artifact remains eligible on the collector Node while ProxyCli setup state is enabled; the extension switch does not control it. Add the two plug-in points that step 2 leaves out: the role firewall rules, and the role baseline and assignment check. Add [amendments A1 and A3](#required-amendments-to-adr-0210) to ADR 0210 and to the code. Remove `ai-router:setup` and `ai-router:teardown`. The origin comes from the role when one is assigned, otherwise from the transitional router URL. `task-vms:prepare-hub` reads it through `ModelKeyIssuer`. Adopt Process 103 on this fleet.
5. **Cleanup.** Remove the `--process` option of `node:role:add`. Remove the transitional router URL, `task_vms.model_proxy_origin`, and `ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN`. Absorb this record into the AI router reference page and retire it.

### Acceptance test

Every pull request in [Build order](#build-order) passes these checks on the live fleet after its Gateway release.

1. The Gateway release finishes, `gateway:release:smoke` passes, and `orbit doctor` shows no new drift.
2. A canary task group on a task VM runs end to end: claim, a Pi turn through the router, handoff and review, settle, and destroy. Afterwards the router's key list is the same as before the claim: the anchor key and no group key.
3. The allowed switch flows hold, and the canary passes again afterwards:
   - `extension:disable ai-router` (or `proxycli` before step 3) while `tasks` is enabled refuses with `extension.required` and changes nothing.
   - `extension:enable tasks` while the router extension is off refuses with `extension.dependency_disabled`.
   - With no open task group: `extension:disable tasks`, `extension:disable ai-router`, `extension:enable ai-router`, `extension:enable tasks`. From step 4 on, the router Process comes back with the same accounts.
   - From step 4 on, `process:destroy` on the router Process refuses with `process.required_by_ai_router`.

[ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) adds the upgrade checks to this test.

### Required amendments to ADR 0210

[ADR 0210](/decisions/0210-give-each-extension-its-own-code-boundary) and its code include A2. The maintainer decided A2 for every extension. It was planned for #1247, which merged without it. The pull request from branch `extension-enable-order` adds it to ADR 0210 and `EnableExtensionAction`, with its tests. A1 and A3 are not added to ADR 0210 yet. The pull request of build step 4 is the first that uses them, so it adds them to ADR 0210 and to the code, with their tests. If ADR 0210 is retired by then, it adds them to the page that absorbed it.

| ID | Amendment | Added by | Why the AI router needs it |
| --- | --- | --- | --- |
| A1 | An extension can refuse to be disabled. A pre-disable guard runs after the `extension.required` check and before the switch changes. It may refuse with a `ResourceOperationException`. | Build step 4 | `ai_router.keys_outstanding` must refuse before the switch turns off. ADR 0210 turns the switch off and then calls `disable()`. |
| A2 | The Gateway runs `enable()` first and turns the switch on only after the hook succeeds. A failed hook leaves the switch off and returns its error. | The follow-up pull request to ADR 0210 and `EnableExtensionAction` from branch `extension-enable-order`, before build step 1 | The maintainer decided this rule for every extension. Tasks must never see the router switch on without a router. |
| A3 | An `enable()` or `disable()` hook may do fleet work over SSH in the request, within 180 seconds ([ADR 0218](/decisions/0218-take-long-commands-off-the-request-path#commands-that-stay-in-the-request)). It replaces the rule that fleet work belongs in a separate setup command. | Build step 4 | The router has one switch and no setup command. |

The new plug-in points in [Plug-in points](#plug-in-points) need no amendment, because ADR 0210 lets each extension add its points.

## Rejected alternatives

- Keep the name `proxycli`: it names one backend and a quota tool, while the concept is the model route for every agent. A second backend would make the name wrong.
- Keep `proxycli` aliases during a transition: [No exceptions and no legacy](/mission#principles) forbids it, and the CLI keeps [no aliases](/reference/cli-command-vocabulary#no-aliases).
- Move the management key into core, so Tasks can use it without the extension: core would own a backend secret, and the router would have two owners.
- Keep Tasks independent and let it fall back to a hand-made CLIProxyAPI: that is a second model route to maintain, and the teardown hazard would stay.
- Let the operator keep creating the router Process by hand: Orbit then cannot refuse its removal, derive the task VM origin, or, with [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades), pin and upgrade its version.
- Make the router an extension without a role, placed by a setup command with `--node`: placement would hide in a setting, Doctor cannot check a setting as a role, and the firewall would need a special case. The analytics role is the precedent for a singleton service.
- A separate setup and teardown command beside the switch: two commands would start the same thing, and today's split is how teardown came to break Tasks.
- Make the collector and its cache optional for a fleet that only wants Tasks: a second mode doubles setup, Doctor checks, and tests. One way is cheaper.
- Redirect `/reference/proxycli` and `/cli/proxycli`: a redirect keeps the old name alive, which [No exceptions and no legacy](/mission#principles) forbids.
- Allow the router on the `ingress` Node: that Node accepts internet traffic, and the router holds every model account's credentials.
- Adopt Process 103 by recreating it under the new name: that restarts the router under in-flight Pi turns. Adoption in place defers the restart to the first upgrade.

## Consequences

- One switch starts and stops the AI router, and it cannot be switched off under Tasks or under a held key. The teardown hazard is gone.
- Tasks has one model route, and core knows it only as `ModelKeyIssuer`. When Tasks moves into its own extension, it keeps that interface or imports the AI router as a declared dependency.
- A second backend becomes a new driver, not a change to Tasks.
- The operator updates the CodexBar URL once.
- Step 4 carries a transitional router URL until the role is assigned. Step 5 removes it.
- The role's cache connection makes Valkey a requirement of the AI router, also for a fleet that only wants Tasks.
- Old links to `/reference/proxycli` and `/cli/proxycli` break.

## Affects

- Components: apps/gateway, apps/cli, apps/web, packages/php-sdk, apps/docs
- ADRs: builds on [ADR 0210](/decisions/0210-give-each-extension-its-own-code-boundary) and needs its [amendments A1 to A3](#required-amendments-to-adr-0210). [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) builds on this record and adds router upgrades. Changes the model proxy origin of [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm): the hub filter and Pi read it from the `ai-router` role.
- Detail: a new AI router reference page and `ai-router` CLI page that replace [proxycli](/reference/proxycli) and [proxycli commands](/cli/proxycli); [Compute drivers: Configure task VMs](/reference/compute-drivers#configure-task-vms) and its hub filter; [Pi server: Run Pi on a task VM](/reference/pi-server#run-pi-on-a-task-vm); [doctor](/cli/doctor); [extension](/cli/extension); [CLI command vocabulary](/reference/cli-command-vocabulary); [Private DNS](/reference/private-dns); [Node provisioning](/reference/node-provisioning#converge-the-orbit-footprint).
- Verify: the [Acceptance test](#acceptance-test) after each step; the extension dependency and refusal tests; a `ModelKeyIssuer` contract test; a migration test that keeps the management key, each anchor, and each task VM key and origin; the role conflict and adoption tests; and the architecture test that keeps `App\` free of `Orbit\Extensions\AiRouter\`.
