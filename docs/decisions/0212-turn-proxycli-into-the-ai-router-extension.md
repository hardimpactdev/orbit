---
title: "ADR 0212: Turn ProxyCli into the AI router extension"
sidebarTitle: "0212 AI router"
description: "In progress. ProxyCli became the ai-router extension and Tasks reaches it through a core model key interface. The singleton ai-router role, the Orbit-managed router Process, and the cliproxyapi driver remain. ADR 0214 adds router upgrades."
---

# ADR 0212: Turn ProxyCli into the AI router extension

The `proxycli` extension becomes the `ai-router` extension, and every surface takes the new name with no alias. CLIProxyAPI becomes its `cliproxyapi` driver. A new singleton `ai-router` Node role places an Orbit-managed router Process and its quota collector. Tasks requires the AI router, reaches it through a small core interface, and the AI router cannot be disabled while Tasks is enabled. [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) builds on this record: it pins the router version, flags a newer upstream release, and adds `ai-router:update`.

## Status

In progress.

Build steps 1 to 3 are built: the core seam, the plug-in points, and the move and rename. Steps 4 and 5 are open.

Principle: [One way, one name](/mission#principles). Task agents get models through one route, and that route has one name in the CLI, API, MCP, database, and documentation. It also serves [No exceptions and no legacy](/mission#principles): no `proxycli` alias stays, and Orbit refuses Tasks without the router. Exception, during the build only: step 4 keeps the stored router URL as the task VM origin until the operator assigns the `ai-router` role, so live task VMs keep their keys. Step 5 removes it.

## Context

CLIProxyAPI is not managed by Orbit. On this fleet it is the systemd Process `cli-proxy-api` (ID 103) on the `services` Node, which an operator created by hand. It runs `~orbit/.local/share/cliproxyapi/current/cli-proxy-api` with `~orbit/.config/cliproxyapi/config.yaml`.

[Tasks](/reference/tasks) depends on CLIProxyAPI through the extension that stores its management key. Pi on a [task VM](/reference/pi-server#run-pi-on-a-task-vm) sends every model call straight to the router origin. `TaskVmRuntime::prepare()` uses the management key to register a per-group model key and an anchor key, and `release()` revokes the group's key.

`proxycli:teardown` deleted the management key. After a teardown, task VM preparation failed and the Gateway had no way to revoke a held key. Nothing prevented it. That hazard is the reason for this record.

[ADR 0210](/decisions/0210-give-each-extension-its-own-code-boundary) gives each extension its own directory, provider, and namespace, and lets an extension declare `dependencies()`. Enabling refuses with `extension.dependency_disabled` while a dependency is off, and disabling refuses with `extension.required` while an enabled extension depends on it.

## Decision

The `ai-router` extension owns model access for agents. A singleton role places it, a driver runs its backend, and Tasks declares it as a dependency. The sections below define the names, the driver contract, the role, the plug-in points, the migration, and the build order.

### One name: the AI router

The concept is the **AI router**: the Orbit service that gives agents model access through one keyed endpoint. The extension slug, the role name, the CLI family, the MCP prefix, and the API path segment are `ai-router`. Error codes and setting keys use `ai_router`, as multiword prefixes such as `task_vm` and `database_connection` do. The PHP namespace is `Orbit\Extensions\AiRouter\`, in `apps/gateway/extensions/ai-router/`.

Every `proxycli` name moved at once, with no alias, redirect command, or old error code. The surfaces that remain to change are the ones step 4 adds:

| Old | New |
| --- | --- |
| `proxycli:setup`, `proxycli:teardown` | Removed. `node:role:add NODE ai-router --cache-connection=SLUG`, then `extension:enable ai-router`; `extension:disable ai-router` |
| Config `task_vms.model_proxy_origin`, `ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN` | Removed. The origin comes from the role |
| Column `task_vms.model_proxy_origin` | `task_vms.ai_router_origin` (built) |
| Process `cli-proxy-api` (operator-made) | Process `ai-router` (Orbit-managed) |
| None | Gateway variable `ORBIT_AI_ROUTER_VERSION`, the driver version that a new router installs |
| Suggested management Route `cli-proxy-api.orbit` | `ai-router.orbit`, still an operator-owned custom proxy Route |

`ai-router:account:enable` and `:disable` use the toggle pair of the [command vocabulary](/reference/cli-command-vocabulary#verb-pairs). Providers and models use `list` and `show` under their own nouns, so the family keeps one family action, `status`. [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) adds the second, `update`.

CodexBar reads the collector by URL and token. The operator changes its URL to `https://collector.ai-router.orbit` once. The read token stays the same.

### Drivers

The AI router runs its backend through a **driver**, as Tasks runs agents through the `pi` driver and task VMs run on [compute drivers](/reference/compute-drivers). Orbit builds one driver, `cliproxyapi`. There is no driver selection input until a second driver exists. The contract is a PHP interface inside the extension, not a core interface. It gives the extension's tests a recording fake and keeps the backend replaceable.

| Capability | What the driver does |
| --- | --- |
| Install and run | Installs a given version on the role Node, checked against its published digest. Writes the first configuration with the stored management key. Returns the router Process specification. |
| Adopt | Checks that an existing Process runs this driver's program, and reports its installed version and configuration paths. |
| Issue and revoke keys | Registers a per-group model key and keeps one anchor key present, so the key list is never empty. Revokes a key and confirms that it no longer works. |
| Report quota and accounts | Supplies the collector program, which writes the shared snapshot of provider pools, windows, and models, and toggles one account. |
| Report the installed version | Reports the version that runs on the Node, for adoption and for [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades). |

The Process specification holds the command, user, port, and restart policy. Every driver step is idempotent. A driver never overwrites configuration that the router changes at run time, such as its key list and its account files. The driver keeps today's layout: versions under `~orbit/.local/share/cliproxyapi/`, the `current` link, the configuration in `~orbit/.config/cliproxyapi/config.yaml`, and port 8317. Adoption therefore moves no file.

### Tasks requires the AI router

Pi agents on task VMs get model access only through the AI router. Orbit maintains no other model route.

- The `tasks` extension declares `ai-router` in `dependencies()`. The AI router may run without Tasks.
- While Tasks lives in `App\`, it reaches the router through one core interface, `App\Domain\TaskVms\ModelKeyIssuer`: `origin()`, `issue(origin, key)`, and `revoke(origin, key)`. The `ai-router` extension binds the implementation. Core never sees the management key or the anchor.
- `TaskVmRuntime` creates the group key and stores the key and its origin on the task VM row before it calls `issue()`, so a retry registers the same key. `release()` calls `revoke()` with the origin stored on the row.

### Disabling is refused while it would strand a key

`extension:disable ai-router` refuses with HTTP 409 `extension.required` while `tasks` is enabled, and with `ai_router.keys_outstanding` while any task VM row holds a model key. Both refusals run before the switch changes; `ai_router.keys_outstanding` needs [amendment A1](#required-amendments-to-adr-0210).

Disabling removes the router Process, the collector Process, the collector's Caddy site and certificate, its private DNS record, and the role's firewall rules. It keeps the router's configuration and account files on the Node, and the stored management key, anchors, and tokens, so the next `extension:enable ai-router` resumes with the same accounts and CodexBar token.

### The `ai-router` role

`ai-router` is a core role in `RoleName` and `RoleRegistry`, like `analytics`. Its behavior is extension code: assignment checks, convergence, removal, and firewall rules reach core through the [plug-in points](#plug-in-points). Core names the role only by its `RoleName` case.

| Property | Value |
| --- | --- |
| Singleton | Yes, one per fleet |
| Assignable during `node:add` | No. Assignment needs a cache connection |
| Relocatable | No. A move changes the origin of every held key. Remove and add instead, when no key is held |
| Conflicts | `gateway` and `ingress` |
| Node | An active Linux Node with a WireGuard address, outside `task_vms.wireguard_range` |

The router holds the credentials of every model account. So it stays off the public-facing `ingress` Node. It also stays off the `gateway` Node: a Gateway move must not move the model route.

`orbit node:role:add NODE ai-router --cache-connection=SLUG [--process=ID]`:

- `--cache-connection` is always required. The collector and its Valkey cache are part of every AI router. It follows the rules `ai_router.cache_missing`, `ai_router.cache_invalid`, and `ai_router.cache_unplaced`.
- `--process` adopts an existing router Process on that Node. It exists only to adopt Process 103. Step 4 adds it, and step 5 removes it.
- While the extension is off, the assignment places nothing. It records the Node and the cache connection, and `--process` marks the adopted Process.

`node:role:remove NODE ai-router` refuses with `ai_router.role_required` while the extension is enabled. Removal deletes the stored management key, anchors, and read and control tokens. It leaves the router's files on the Node.

The task VM origin is `http://<role Node WireGuard address>:<driver port>`, for example `http://10.44.0.3:8317`.

| Rule | Allows |
| --- | --- |
| `orbit:ai-router-proxy` | TCP 8317 to the role Node's WireGuard address on the `orbit` interface. The public interface has no rule. |
| `orbit:ai-router-https` | TCP 443 on the `orbit` interface, for the collector site. |
| Hub filter | `task-vms:prepare-hub` reads the router endpoint from the role. Run it again after the role is first assigned. |

### An Orbit-managed router Process

`extension:enable ai-router` refuses with `ai_router.role_missing` while no Node holds an active `ai-router` role. Otherwise its `enable()` hook converges, idempotently:

1. The router Process `ai-router` on the role Node, through the driver, at the version in `ORBIT_AI_ROUTER_VERSION`. [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) replaces that variable with a stored pin. The install uses the stored management key and generates one only when none is stored. An adopted Process keeps its key.
2. The collector Process `ai-router-collector` on the same Node, with the cache connection of the role.
3. The collector's Orbit CA leaf, its Caddy site, and the private DNS record `collector.ai-router.orbit`.
4. The role's firewall rules.

With [amendment A2](#required-amendments-to-adr-0210), the hook runs first and the switch turns on only after it succeeds. A failed step leaves the switch off, and the next `extension:enable ai-router` reuses the placed parts. So the hook runs while the switch is off, and nothing it calls reads the switch. It calls the extension's actions and the driver directly, and the Caddy site source, private DNS, reserved hostname, footprint artifact, firewall rules, and Process removal guard decide from the placement record. [Amendment A3](#required-amendments-to-adr-0210) lets the hook do fleet work over SSH, so one switch is the one way to start and stop the AI router.

Both Processes are Orbit-managed, also while the switch is off. `process:destroy` refuses them with `process.required_by_ai_router`. Only `extension:disable ai-router` removes them.

### Plug-in points

Each point is a typed core interface that the extension tags. The pull request that adds a point deletes the hard-coded call it replaces. The points of steps 1 and 2 are built. Step 4 adds the two that need `RoleName::AiRouter`:

| Point | Core caller | Replaces |
| --- | --- | --- |
| Role firewall rules | `NodeFirewallRuleCatalog` | None |
| Role baseline and assignment check | `NativeRoleBaselineConverger`, `AddNodeRoleAction`, `node:role:remove` | None. The `ai-router` arm resolves the tagged `RoleBaseline` instead of naming a class |

### Migration of live state

One rule governs every step: **no task VM that holds a key loses the ability to revoke it.** So every step keeps the management key, each anchor under the same origin hash, every task VM's `model_key`, and its origin byte for byte. No step changes the router's key list. Any operation that would change the origin or drop the management key refuses while a task VM holds a key.

Adoption of Process 103:

1. `orbit node:role:add services ai-router --cache-connection=services-valkey --process=103`.
2. The Gateway checks that the Process is a systemd Process on that Node, that the driver recognizes its program, that a management key is stored, and that the derived origin `http://10.44.0.3:8317` equals the transitional router URL and the origin of every task VM that holds a key. A mismatch refuses with `ai_router.adoption_refused` and changes nothing.
3. The Gateway marks Process 103 as the Orbit-managed router and records its installed version. It does not rewrite the unit, rename the Process, or restart it. In-flight Pi turns see no change.
4. Process 103 keeps running unchanged. The first router upgrade of [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) replaces it as `ai-router`. That is the first restart, and the operator chooses its time.

### Build order

Each step is one pull request and keeps Tasks working. Each passes the [acceptance test](#acceptance-test) before merge. The router upgrades of [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) come after these five steps.

1. **Core seam and hazard fix.** Built.
2. **Plug-in points.** Built.
3. **Move and rename.** Built.
4. **Role, driver, and managed router.** Remove the one-time rename machinery: drop `ai_router_cutovers`; delete `AiRouterRenameCutover`, the pending tick predicate, status field, setup and teardown guards, cache `renameCollectorKeys()`, the optional retired-path arguments on the source and certificate removal commands, and the one-time cutover tests and fixtures. Keep permanent collector convergence and footprint activation tests. Then add the `ai-router` role, the driver contract, the `cliproxyapi` driver, adoption with `--process`, and the `enable()` and `disable()` hooks. `disable()` withdraws the collector's Caddy site, certificate, and private DNS record. Add the two plug-in points above, and [amendments A1 and A3](#required-amendments-to-adr-0210) to ADR 0210 and the code. Remove `ai-router:setup` and `ai-router:teardown`. The origin comes from the role when one is assigned, otherwise from the transitional router URL. `task-vms:prepare-hub` reads it through `ModelKeyIssuer`. Adopt Process 103 on this fleet.
5. **Cleanup.** Remove the `--process` option of `node:role:add`. Remove the transitional router URL, `task_vms.model_proxy_origin`, and `ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN`. Absorb this record into the AI router reference page and retire it.

### Acceptance test

Every pull request in [Build order](#build-order) passes these checks on the live fleet after its Gateway release.

1. The Gateway release finishes, and `orbit doctor` shows no new drift.
2. A canary task group on a task VM runs end to end: claim, a Pi turn through the router, handoff and review, settle, and destroy. Afterwards the router's key list is the same as before the claim: the anchor key and no group key.
3. The allowed switch flows hold, and the canary passes again afterwards:
   - `extension:disable ai-router` while `tasks` is enabled refuses with `extension.required` and changes nothing.
   - `extension:enable tasks` while the router extension is off refuses with `extension.dependency_disabled`.
   - With no open task group: `extension:disable tasks`, `extension:disable ai-router`, `extension:enable ai-router`, `extension:enable tasks`. From step 4 on, the router Process comes back with the same accounts.
   - From step 4 on, `process:destroy` on the router Process refuses with `process.required_by_ai_router`.

[ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) adds the upgrade checks to this test.

### Required amendments to ADR 0210

[ADR 0210](/decisions/0210-give-each-extension-its-own-code-boundary) and its code include A2. A1 and A3 are not added yet. The pull request of build step 4 is the first that uses them, so it adds them to ADR 0210 and to the code, with their tests. If ADR 0210 is retired by then, it adds them to the page that absorbed it.

| ID | Amendment | Why the AI router needs it |
| --- | --- | --- |
| A1 | An extension can refuse to be disabled. A pre-disable guard runs after the `extension.required` check and before the switch changes. It may refuse with a `ResourceOperationException`. | `ai_router.keys_outstanding` must refuse before the switch turns off. |
| A2 | The Gateway runs `enable()` first and turns the switch on only after the hook succeeds. A failed hook leaves the switch off and returns its error. | Tasks must never see the router switch on without a router. |
| A3 | An `enable()` or `disable()` hook may do fleet work over SSH in the request, within 180 seconds ([ADR 0218](/decisions/0218-take-long-commands-off-the-request-path#commands-that-stay-in-the-request)). | The router has one switch and no setup command. |

## Rejected alternatives

- Keep the name `proxycli`, or its aliases: it names one backend, and [No exceptions and no legacy](/mission#principles) forbids aliases.
- Move the management key into core: core would own a backend secret, and the router would have two owners.
- Let Tasks fall back to a hand-made CLIProxyAPI: that is a second model route, and the teardown hazard would stay.
- Let the operator keep creating the router Process by hand: Orbit then cannot refuse its removal, derive the task VM origin, or pin and upgrade its version.
- An extension without a role, placed by a setup command with `--node`: Doctor cannot check a setting as a role, and the firewall would need a special case.
- A separate setup and teardown command beside the switch: two commands would start the same thing, and that split is how teardown came to break Tasks.
- Make the collector and its cache optional: a second mode doubles setup, Doctor checks, and tests.
- Allow the router on the `ingress` Node: that Node accepts internet traffic, and the router holds every model account's credentials.
- Adopt Process 103 by recreating it under the new name: that restarts the router under in-flight Pi turns.

## Consequences

- One switch starts and stops the AI router, and it cannot be switched off under Tasks or under a held key. The teardown hazard is gone.
- Tasks has one model route, and core knows it only as `ModelKeyIssuer`.
- A second backend becomes a new driver, not a change to Tasks.
- The operator updates the CodexBar URL once.
- The role's cache connection makes Valkey a requirement of the AI router, also for a fleet that only wants Tasks.
- Old links to `/reference/proxycli` and `/cli/proxycli` break.

## Affects

- Components: apps/gateway, apps/cli, apps/web, packages/php-sdk, apps/docs
- ADRs: builds on [ADR 0210](/decisions/0210-give-each-extension-its-own-code-boundary) and needs its [amendments A1 to A3](#required-amendments-to-adr-0210). [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) builds on this record and adds router upgrades. Changes the [task VM model proxy origin](/reference/compute-drivers#limit-fleet-traffic-on-the-hub): the hub filter and Pi read it from the `ai-router` role.
- Detail: the AI router reference page and `ai-router` CLI page; [Compute drivers: Configure task VMs](/reference/compute-drivers#configure-task-vms) and its hub filter; [Pi server: Run Pi on a task VM](/reference/pi-server#run-pi-on-a-task-vm); [doctor](/cli/doctor); [extension](/cli/extension); [CLI command vocabulary](/reference/cli-command-vocabulary); [Private DNS](/reference/private-dns); [Node provisioning](/reference/node-provisioning#converge-the-orbit-footprint).
- Verify: the [Acceptance test](#acceptance-test) after each step; the extension dependency and refusal tests; a `ModelKeyIssuer` contract test; a migration test that keeps the management key, each anchor, and each task VM key and origin; the role conflict and adoption tests; and the architecture test that keeps `App\` free of `Orbit\Extensions\AiRouter\`.
