---
title: "Extensions"
description: "How an optional Gateway feature lives in its own directory, contributes to core through plug-in points, and stays behind one switch."
covers:
  - apps/gateway/app/{Domain,Actions}/Extensions/**
  - apps/gateway/app/Providers/{ExtensionServiceProvider,TasksServiceProvider}.php
  - apps/gateway/app/Domain/{Projects/ProjectRemovalHook,GatewayReleases/GatewayReleaseCleanup,Activity/ActivityInputRedaction,Gateway/GatewayServiceStartHook,Gateway/ExactRequestBody,Fleet/NodeFootprintArtifact,Routes/ContributedPrivateHostnames,Routes/RouteKind,Routes/InstanceTrackingPublication,Nodes/RoleName,Nodes/ContributedNodeRole,Nodes/NodeRoleRemovalGuard,Processes/ProcessRemovalGuard,DatabaseConnections/DatabaseConnectionUpdateHook,DatabaseConnections/DatabaseConnectionRemovalGuard}.php
  - apps/gateway/{app/Infrastructure/GatewayReleases/GatewayCleanupHandoff.php,app/Infrastructure/Caddy/Build/ContributedCaddySiteSource.php,app/Infrastructure/Gateway/NativeGatewayFpmConverger.php,app/Infrastructure/Firewall/NodeFirewallRuleCatalog.php,app/Infrastructure/Firewall/ContributedRoleFirewallRules.php,app/Http/Middleware/RequireEnabledExtension.php,app/Http/Middleware/ValidateRequestBodySize.php,resources/remote/gateway/install-start-hooks.sh}
  - apps/gateway/{bootstrap/providers.php,tests/Unit/Domain/Extensions/**}
  - apps/gateway/extensions/*/src/*ServiceProvider.php
  - apps/gateway/extensions/*/database/migrations/*_enable_*_extension_*.php
  - apps/gateway/tests/{Unit/Architecture/ExtensionBoundaryTest,Feature/Api/ExtensionsTest,Feature/Domain/Projects/ProjectRemovalHookTest,Feature/Domain/Activity/ActivityInputRedactionTest,Feature/Http/Middleware/ValidateRequestBodySizeTest,Feature/Infrastructure/Gateway/GatewayServiceStartHookTest}.php
---

# Extensions

An extension is an optional Gateway feature with one switch. [`extension`](/cli/extension) describes the switch, the commands, and what each client does while an extension is off. This page describes how an extension is built: where its code lives, how it adds behavior to core, and the rule that keeps core independent of it.

ADR [0210](/decisions/0210-give-each-extension-its-own-code-boundary) is moving the extensions behind this boundary one at a time. Documents, Vault, Conn, and the AI router have moved. Tasks has a switch and provider, but its code is still in core.

## The extensions

The Gateway has five extensions. This table shows where each one's code lives today.

| Extension | Code | Depends on |
| --- | --- | --- |
| `documents` | `apps/gateway/extensions/documents`. See [Project Documents](/reference/project-documents). | nothing |
| `tasks` | Core, in the `Tasks` and `TaskVms` namespaces. See [Tasks](/reference/tasks). | `ai-router` |
| `ai-router` | `apps/gateway/extensions/ai-router`. See [AI router](/reference/ai-router). | nothing |
| `vault` | `apps/gateway/extensions/vault`. See [Vaultwarden](/reference/vaultwarden). | nothing |
| `conn` | `apps/gateway/extensions/conn`. See [Conn layer](/reference/conn). | nothing |

Analytics is still a core role, not a sixth extension. The proposed [Analytics transition contract](/decisions/0210-give-each-extension-its-own-code-boundary#analytics-transition-contract) keeps its persisted role identity in core and moves behavior through narrow typed contributions, after explicit Process ownership is proved. Its proposed disabled behavior preserves serving and data; no Analytics switch or migration is delivered by the proposal.

Task agent turns and checkout operations share the [workspace lease](/reference/tasks#workspace-lease). Task drivers and remote operations use that ownership rule regardless of which caller starts the work. A disabled extension does not erase an existing holder or its recovery evidence.

## Layout

An extension lives in `apps/gateway/extensions/<name>/`.

| Path | Holds |
| --- | --- |
| `src/` | PHP code under `Orbit\Extensions\<Name>\`: the provider, models, actions, data classes, controllers, form requests, console commands, and infrastructure. |
| `database/migrations/` | The extension's migrations. |
| `routes/api.php` | The extension's API routes. |

The Gateway's `composer.json` maps each namespace with one PSR-4 entry, and `bootstrap/providers.php` lists each provider. Pint, Rector, and PHPStan check `extensions/` like `app/`. The tests stay under `apps/gateway/tests/Feature/Extensions/<Name>` and `apps/gateway/tests/Unit/Extensions/<Name>`.

## The provider

Each extension has one service provider. It extends `App\Providers\ExtensionServiceProvider` and implements `App\Domain\Extensions\Extension`.

| Method | Purpose |
| --- | --- |
| `name()` | The switch slug. |
| `description()` | One sentence that describes the extension. |
| `dependencies()` | The slugs that must be enabled before this extension. The default is none. |
| `enable()` | Runs before the switch turns on. The default does nothing. |
| `disable()` | Runs after the switch turns off. The default does nothing. |

The provider registers what the extension owns: its routes with `loadRoutesFrom()`, its migrations with `loadMigrationsFrom()`, its console commands, its schedule entries, and its plug-in contributions.

`ExtensionRegistry` lists the registered providers that implement `Extension`. That list is the set of known slugs: `extension:list`, `extension:enable`, and `extension:disable` accept only those. No other list of slugs exists in the Gateway.

## Add a plug-in point

Use this checklist when core adds a place for extensions to contribute:

- Define a small typed interface and tag each implementation with the interface name in its provider.
- Inject contributions through the constructor with Laravel's `#[Tag]` attribute. Trusted in-repo implementations need no runtime interface or duplicate-class validation.
- State when the contribution runs and what happens while its extension is disabled.
- Add one unit test per contribution to prove its production wiring, and test the behavior at its caller.
- Keep the architecture test in `tests/Unit/Architecture/ExtensionBoundaryTest.php` passing so core never imports extension classes.

The `ci-runner` role is a core Node role. Its host boundary uses the existing role baseline and firewall catalog. It introduces no extension contribution or plug-in point.

## Plug-in points

Core defines an interface for each place where an extension can add behavior. The provider tags its implementation with the interface name:

```php
$this->contribute(ProjectRemovalHook::class, DocumentsProjectRemoval::class);
```

Core receives tagged implementations through constructor parameters marked with Laravel's `#[Tag(Interface::class)]` attribute and typed as `iterable<Interface>`. Each caller uses the typed interface directly.

| Interface | Called by | Methods |
| --- | --- | --- |
| `App\Domain\Projects\ProjectRemovalHook` | `project:destroy` | `lock()` and `remove()` |
| `App\Domain\GatewayReleases\GatewayReleaseCleanup` | The [release handoff](/reference/gateway-recovery) | `configured()`, `status()`, `reconcile()`, and `resume()` |
| `App\Domain\Activity\ActivityInputRedaction` | The [Activity](/cli/activity) recorder | `commands()` and `input()` |
| `App\Infrastructure\Caddy\Build\ContributedCaddySiteSource` | The [Node Caddy build](/reference/caddy-configuration#node-caddy-build) | `sites()` and `certificateSlug()` |
| `App\Domain\Fleet\NodeFootprintArtifact` | [`node:converge`](/reference/node-provisioning#converge-the-orbit-footprint) and the fleet rollout | `name()`, `applies()`, `digest()`, and `apply()` |
| `App\Domain\Routes\ContributedPrivateHostnames` | Route and analytics hostname checks, and private DNS publication | `hostnames()` returns each reserved hostname with its current address, or `null` when it must not be published |
| `App\Domain\Gateway\GatewayServiceStartHook` | Gateway [web convergence](/reference/web-app#web-directory) and the [release handoff](/reference/gateway-recovery#runtime-handoff) | `name()`, `dropIn()`, `description()`, and `renderHook()` |
| `App\Domain\Processes\ProcessRemovalGuard` | [`process:destroy`](/cli/process#orbit-processdestroy) | `assertRemovable()` |
| `App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook` | [`database:update`](/cli/database#orbit-databaseupdate) | `validate()` and `afterCommit()` |
| `App\Domain\DatabaseConnections\DatabaseConnectionRemovalGuard` | [`database:destroy`](/cli/database#orbit-databasedestroy) | `assertRemovable()` |
| `App\Domain\Gateway\ExactRequestBody` | The request body size check | `matches()` |

`ProcessRemovalGuard::assertRemovable()` runs under the Process runtime lease before removal changes the record or the runtime. A guard may refuse with a `ResourceOperationException`. Core tags the Analytics and Database server guards; the AI router tags its collector guard from its provider. The action receives all guards through `#[Tag]` and calls each one. Guard order is not part of the contract: a guard must not depend on another guard running first. The owning feature can still remove its own Process through the existing internal removal path.

`ProjectRemovalHook::lock()` runs first in the removal transaction, before the guards, so the extension's writers wait for the removal. `remove()` runs after every guard passed and before the Project row is deleted. It may refuse with a `ResourceOperationException`, which rolls back the whole removal.

`DatabaseConnectionUpdateHook::validate()` receives the proposed connection inside the update transaction, before the write. A refusal changes neither the connection nor the collector. `afterCommit()` receives the saved connection only after the outer transaction commits; rollback skips it. A reconvergence failure leaves the connection saved, so repeating the update retries it. `DatabaseConnectionRemovalGuard::assertRemovable()` runs before any database drop or record deletion. Contributions must not depend on their order. The AI router contributes its cache validation, collector reconvergence, and cache ownership guard, even while the extension switch is off.

After the release handoff restarts the scheduler, it waits for each configured `GatewayReleaseCleanup` to report a new generation. Then it reconciles and resumes that cleanup. The release records one cleanup outcome: `paused` when any cleanup stays paused, otherwise `resumed`, `running`, or `skipped`.

`ActivityInputRedaction::commands()` names the route names whose input the extension decides. For those commands, Activity stores only what `input()` returns, then sanitizes it like any other input. `input()` receives a closure that returns the request input. Activity runs before authorization, so the request body is parsed only when the extension calls that closure. Conn uses it so that a T3 admin session and the content of a settings document never enter Activity. Documents never calls the closure, so document bytes are never parsed or recorded before authorization.

The Node Caddy build renders core's sources first, then each `ContributedCaddySiteSource` in provider order. Every source renders from stored state only. For an Orbit CA certificate, `certificateSlug()` names its slug for `CaddySiteCertificates`; the site renders only after its publisher records the certificate on the Node. The [Caddy footprint digest](/reference/node-provisioning#converge-the-orbit-footprint) covers the source and its dependencies, so template changes reach the Node at the next fleet rollout. The AI router contributes the collector site. Switching it off keeps the deployed site; teardown withdraws that site and certificate without changing other site blocks.

A contribution owns its source names and certificate slug. Its unit test checks those identities and its production wiring.

The Node footprint applies core's artifacts first, then each contributed `NodeFootprintArtifact` in provider order. Each artifact owns its name, because the footprint keys its digests by name. The AI router contributes the `ai-router` artifact.

`ContributedPrivateHostnames::hostnames()` returns a map from each reserved hostname to its current address, or `null` when it must remain reserved without a DNS record. Private DNS publishes each non-null address as a `host-record`. AI router publishes the collector Node's stored `wireguard_ip`.

`Ipv4Subnet::containsUsableAddress()` defines usable IPv4 hosts in the configured VPN subnet, excluding network and broadcast addresses. `WireGuardAddressAllocator::forProvisioning()` applies this rule during Node provisioning and macOS enrollment; `GatewayBootstrapIdentityValidator` applies it during Gateway bootstrap, before either path stores the address. The contribution does not validate that address again. Its test checks that the hostname is unique, lowercase, under `.orbit`, and free of commas and whitespace, and that publication follows the Node's stored address.

The digest of the `private-dns` [footprint artifact](/reference/node-provisioning#converge-the-orbit-footprint) covers each contribution and the classes it uses.

The reserved hostnames are core's names, then the keys of each contribution's map. New Routes and Route domain changes cannot take a reserved name. Existing Routes remain published; Doctor reports them as `route.domain_reserved`. An operator must change or remove each conflicting Route. Analytics tracking hosts cannot use any name under `.orbit`. AI router reserves `collector.ai-router.orbit` even while it is off or not set up, and publishes it while setup holds the collector.

`GatewayServiceStartHook` adds a program that runs as root each time the Gateway PHP-FPM service starts. Web convergence and the release handoff install every contributed hook with the [remote program](/reference/remote-programs) `gateway.install-start-hooks`. It writes the program as `/etc/orbit/<name>` and a `php8.5-fpm` drop-in that runs it in `ExecStartPre`. A file that already holds its bytes, owner, and mode stays as it is. Before either caller reloads the pool, it runs each program, because a reload skips `ExecStartPre`. The handoff reloads only when the pool changed. A failed program stops the activation, and the error names the hook. Documents uses the point to invalidate document-body cleanup on each start.

Core checks every hook before it changes anything:

- A name ends in `-start`. No core path in `/etc/orbit` does, so a hook cannot take a core file or directory.
- A drop-in is `orbit-*.conf`, which systemd reads, and not `orbit-vpn.conf`, which core writes. Its name need not share the program's stem.
- A description is one printable line, so it cannot add a directive to the drop-in.
- No two hooks share a name or a drop-in.

The program replaces a file with `mv -fT`, so it never moves a hook into a directory that holds its name. It creates a missing directory and leaves the mode of an existing one, such as the 0711 of a shared `/etc/orbit`. It holds a lock on `/etc/orbit` while it reads and writes, so two convergences never interleave.

The program records the hooks it installed in `/etc/orbit/php-fpm-start-hooks`. Each run removes a listed hook that no extension contributes: its drop-in first, then one `daemon-reload`, then its program. A hook stays listed until a run finishes, so a retry after a failed reload still reloads before it removes the program. The program also reloads when systemd reports that the unit changed on disk. Core removes only the files its manifest lists. A Gateway without a manifest keeps every file it has, and the first run records the current hooks. So the next convergence after an extension is deleted removes its hooks.

`ExactRequestBody::matches()` receives the request path, never the body. For a matching path, the Gateway skips PHP's `post_max_size` check and does not trim strings or turn empty strings into null. The body stays exact and is not parsed before authorization, so the route must bound it after authorization. Contribute a matcher only for a route that bounds its body itself after authorization. Core keeps the two MCP envelopes exact itself. Documents matches its document routes, which accept up to 15 MiB of JSON. The Gateway asks the matchers once per request.

A plug-in contribution runs whether or not the switch is on. Removing a Project must also remove its documents, a scheduled deletion must finish, and a request to a disabled extension still records Activity, so its secrets must still stay out. Each PHP-FPM start must also close the document cleanup gate. A Caddy build renders every site that is placed, so a site stays live until the extension removes it. A reserved hostname stays reserved, so no Route can take it while the extension is off.

## The boundary

`tests/Unit/Architecture/ExtensionBoundaryTest.php` fails when a class under `App\` uses a class under `Orbit\Extensions\`. An extension may use core models, actions, data classes, and plug-in interfaces. Core reaches an extension only through a plug-in interface.

## The runtime switch

The boundary decides who owns code, not what loads. Routes, migrations, console commands, and MCP tools are always registered, and migrations always run, so every install has the same schema.

An extension's route group adds the middleware `RequireEnabledExtension:<name>`. While the switch is off, each of its operations refuses with HTTP 409 `extension.disabled` and changes nothing. `bin/docs-openapi` marks those operations with `x-orbit-extension`. The MCP server leaves them out of its tool list while the switch is off.

Background work that finishes recorded work keeps running while the switch is off. Work that is the feature itself stops: the Vaultwarden sync, `orbit:vault-sync`, runs only while `vault` is on. Its first run after the switch turns on again publishes, rewrites, and deletes whatever changed meanwhile.

### Enable and disable

`extension:enable` refuses with HTTP 409 `extension.dependency_disabled` while a dependency is off. `extension:disable` refuses with HTTP 409 `extension.required` while an enabled extension depends on the one being disabled. Both name the other extension in `error.details`.

`extension:enable` runs the extension's `enable()` hook first and turns the switch on only after the hook returns. A failed enable leaves the switch off: when the hook throws, the switch keeps its earlier state and the API returns the hook's error code, message, and details. The hook runs while the switch is still off, so it must not rely on the switch. `extension:disable` turns the switch off, then runs `disable()`. Both run in the same request. Enabling an enabled extension runs `enable()` again, so a hook must be safe to repeat. Tasks uses its hooks to broadcast the [`tasks.updated`](/reference/events#tasks) event. Documents, Vault, and Conn use neither.

Tasks depends on `ai-router`, because Pi on a task VM reaches models only through its CLIProxyAPI. Task VM code calls the core interface `App\Domain\TaskVms\ModelKeyIssuer`, and the `ai-router` provider binds it. The Tasks `enable()` hook calls `ModelKeyIssuer::assertReady()`, so `extension:enable tasks` refuses with `ai_router.disabled` until ai-router is set up. `ai-router:teardown` refuses while `tasks` is enabled or a task VM holds a model key. See [Teardown](/reference/ai-router#teardown).

`App\Domain\Extensions\ExtensionDependencies::assertNotRequired()` is the `extension.required` check. `extension:disable` uses it, and so does a command that removes what another extension needs, such as `ai-router:teardown`.

### Defaults

A new install starts with every extension off. When an existing install updates, a migration turns an extension on if the install already uses it:

| Extension | Turned on when the install has |
| --- | --- |
| `documents` | A document storage configuration, or at least one document entry. |
| `vault` | A stored Vaultwarden setup, or at least one published item in `vault_items`. |
| `conn` | At least one registered T3 server or one profile. |

A current user keeps the feature without action, and a new install does not carry a feature it has not asked for.

## Why it works this way

An optional feature should cost core nothing when it is off, and Orbit should be able to delete it when nothing uses it. Both need core to stay free of extension code. A directory per extension makes the code easy to find, and the architecture test keeps core from reaching back. Keeping extension code in core folders with only a provider around it would leave the boundary in a reviewer's head.

Each plug-in point is a typed interface. It states what an extension may change and what core expects back. A generic event bus would hide that contract and the order of calls. Core adds a point only when an extension needs it.

Routes and migrations load on every install. The Gateway caches its routes, and the OpenAPI document and the MCP tools are generated from them. Loading them from a database switch would make those artifacts and the schema depend on runtime state.

An extension is not a Composer package, a separate process, or something to install at runtime. Nothing outside this repository installs one, so a package for each extension would add lock file and tooling churn for no gain.
