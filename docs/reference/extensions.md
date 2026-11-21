---
title: "Extensions"
description: "How an optional Gateway feature lives in its own directory, contributes to core through plug-in points, and stays behind one switch."
covers:
  - apps/gateway/app/Domain/Instances/{InstanceLifecycleContribution,InstanceThreadContribution}*.php
  - apps/gateway/app/{Domain,Actions}/Extensions/**
  - apps/gateway/app/Providers/{ExtensionServiceProvider,TasksServiceProvider}.php
  - apps/gateway/app/Domain/{Projects/ProjectRemovalHook,GatewayReleases/GatewayReleaseCleanup,Activity/ActivityInputRedaction,Gateway/ExactRequestBody,Fleet/NodeFootprintArtifact,Routes/ContributedPrivateHostnames,Routes/RouteKind,Nodes/RoleName,Processes/ProcessRemovalGuard,Processes/ProcessOwnershipClaim,DatabaseConnections/DatabaseConnectionUpdateHook,DatabaseConnections/DatabaseConnectionRemovalGuard}.php
  - apps/gateway/{app/Infrastructure/GatewayReleases/GatewayCleanupHandoff.php,app/Infrastructure/Caddy/Build/ContributedCaddySiteSource.php,app/Infrastructure/Firewall/NodeFirewallRuleCatalog.php,app/Infrastructure/Firewall/ContributedNodeFirewallRules.php,app/Http/Middleware/RequireEnabledExtension.php,app/Http/Middleware/ValidateRequestBodySize.php}
  - apps/gateway/bootstrap/providers.php
  - apps/gateway/extensions/*/src/*ServiceProvider.php
---

# Extensions

An extension is an optional Gateway feature controlled by a single runtime switch. [`extension`](/cli/extension) documents the CLI commands and client behavior. This page explains how extensions are structured, how they plug into core, and how the code boundary is enforced.

## The extensions

The Gateway ships five extensions (Analytics remains a core role):

| Extension | Code location | Depends on |
| --- | --- | --- |
| `documents` | `apps/gateway/extensions/documents` ([Project Documents](/reference/project-documents)) | none |
| `ai-router` | `apps/gateway/extensions/ai-router` ([AI router](/reference/ai-router)) | none |
| `tasks` | Core `Tasks` / `TaskVms` namespaces ([Tasks](/reference/tasks)) | `ai-router` |
| `vault` | `apps/gateway/extensions/vault` ([Vaultwarden](/reference/vaultwarden)) | none |
| `conn` | `apps/gateway/extensions/conn` ([Conn layer](/reference/conn)) | none |

## Layout

Each extracted extension lives in `apps/gateway/extensions/<name>/` under PSR-4 namespace `Orbit\Extensions\<Name>\`:

| Path | Contents |
| --- | --- |
| `src/` | Service provider, models, actions, data classes, controllers, form requests, commands, and infrastructure. |
| `database/migrations/` | New extension migrations (existing schema lives in the Gateway baseline). |
| `routes/api.php` | Extension API routes. |

Providers are listed in `bootstrap/providers.php`. Pint, Rector, and PHPStan check `extensions/` alongside `app/`, and tests live under `apps/gateway/tests/{Feature,Unit}/Extensions/<Name>`.

## The provider

Each extension registers one provider extending `App\Providers\ExtensionServiceProvider` and implementing `App\Domain\Extensions\Extension`:

| Method | Purpose |
| --- | --- |
| `name()` | Switch slug (`ExtensionRegistry` builds the authoritative slug list from registered providers). |
| `description()` | One-sentence description. |
| `dependencies()` | Extension slugs that must be enabled first (default none). |
| `enable()`, `disable()` | Optional hooks run before enabling or after disabling (used by `tasks` to broadcast `tasks.updated`; unused by `documents`, `vault`, `conn`, and `ai-router`). |

The provider registers its routes, commands, scheduled work (for example `conn:environments:renew` hourly and `conn:threads:read` every minute on `conn`), and plug-in contributions via `$this->contribute(Interface::class, Implementation::class)`.

## Add a plug-in point

Follow this checklist when adding a new core plug-in interface:

- Define a narrow typed interface in core and tag implementations in each extension provider.
- Inject tagged implementations via Laravel's `#[Tag(Interface::class)]` attribute (`iterable<Interface>`).
- Document whether the contribution runs while its extension is disabled.
- Add a unit test verifying production wiring and caller behavior.
- Keep `tests/Unit/Architecture/ExtensionBoundaryTest.php` green so `App\` never imports `Orbit\Extensions\`.

## Plug-in points

Core calls extensions exclusively through these typed interfaces:

| Interface | Caller | Purpose |
| --- | --- | --- |
| `App\Domain\Processes\Artifacts\ProcessArtifactPreset` | [`process:artifact:set --preset`](/cli/process/artifact-set) ([Upgrade a Process](/reference/processes-and-schedules#upgrade-a-process)) | `name()` and `fields()` |
| `App\Domain\Projects\ProjectRemovalHook` | `project:destroy` | Lock and remove extension resources before deleting a Project. |
| `App\Domain\Projects\ProjectAgentInstructionSource` | Container clone create, `project:show` | Supply Project agent instructions (`documents` returns none when off). |
| `App\Domain\GatewayReleases\GatewayReleaseCleanup` | `gateway:release:cleanup` | Reconcile and resume extension cleanup after release switch. |
| `App\Domain\GatewayReleases\GatewayReleaseFollowUp` | Post-fleet release pass | Advance release follow-up phases (`ai-router` guard). |
| `App\Domain\Activity\ActivityInputRedaction` | Activity recorder | Redact or skip request body parsing before authorization (`conn`, `documents`). |
| `App\Infrastructure\Firewall\ContributedNodeFirewallRules` | Desired firewall catalog | Declare UFW rules managed by an extension (`ai-router`). |
| `App\Infrastructure\Caddy\Build\ContributedCaddySiteSource` | [Node Caddy build](/reference/caddy-configuration#node-caddy-build) | Render extension Caddy sites and Orbit CA certificate slugs (`ai-router`). |
| `App\Domain\Fleet\NodeFootprintArtifact` | [`node:converge`](/reference/node-provisioning#converge-the-orbit-footprint) | Contribute footprint artifact digests (`ai-router`, core `php-runtime`). |
| `App\Domain\Routes\ContributedPrivateHostnames` | Route checks, private DNS | Reserve `.orbit` hostnames and publish DNS addresses (`collector.ai-router.orbit`). |
| `App\Domain\Processes\ProcessRemovalGuard` | [`process:destroy`](/cli/process/destroy) | Refuse deleting Processes owned by an extension or core feature. |
| `App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook` | [`database:update`](/cli/database/update) | Validate updates and run post-commit hooks (`ai-router` cache, core SQL guard). |
| `App\Domain\DatabaseConnections\DatabaseConnectionRemovalGuard` | [`database:destroy`](/cli/database/destroy) | Refuse deleting connections owned by an extension or core feature. |
| `App\Domain\Operations\DirectOperationContribution` | Direct-start Operation registry | Contribute workflow and projection declarations (`conn`, `vault`, `ai-router`). |
| `App\Domain\Operations\OperationSecretSource` | Private Operation secret resolver | Resolve extension-owned execution secrets (`vault`, `conn`). |
| `App\Domain\Operations\OperationRecordCoordinator` | Operation admission & lifecycle | Coordinate extension-owned Operation records (`conn`). |
| `App\Domain\Gateway\ExactRequestBody` | Request body size middleware | Exempt specific routes from PHP post-size trimming so routes bound bodies after auth (`documents`, core `mcp`/`mcp/search`). |
| `App\Domain\Instances\InstanceLifecycleContribution` | Container create, complete, migrate, destroy, wake | Manage T3 environments, held sessions, and cleanup (`conn`). |
| `App\Domain\Instances\Power\InstanceAgentActivity` | Runtime hibernator sweep | Report live agent activity before sleeping a container clone (`conn`). |
| `App\Domain\Instances\InstanceThreadContribution` | Task copy turn, container sleep | Record threads and refresh thread index before sleep (`conn`). |
| `App\Domain\Nodes\NodeAccessRemovalTransactionHook`, `NodeAccessRemovalHook`, `NodeRemovalHook` | `node:access:remove`, `node:remove` | Record managed revocations, rotate profile event keys, and hand off held sessions (`conn`). |

Most extension contributions stay active while an extension is disabled so cleanup, secret redaction, reserved hostnames, deployed Caddy sites, and active Operations remain consistent. Contributions that implement the feature itself (such as `orbit:vault-sync` or `InstanceAgentActivity` reads) pause while the switch is off.

## The runtime switch

Routes, migrations, console commands, and MCP schemas always register so route caching, OpenAPI generation, and database schemas stay deterministic. Extension routes apply `RequireEnabledExtension:<name>`, returning HTTP 409 `extension.disabled` while off, and MCP hides disabled tools.

`extension:enable` refuses with HTTP 409 `extension.dependency_disabled` if a required dependency is off, runs `enable()` before flipping the switch, and leaves the switch off if the hook throws (for example `extension:enable tasks` refuses with `ai_router.disabled` until AI router is configured). `extension:disable` refuses with HTTP 409 `extension.required` while another enabled extension depends on it, flips the switch off, and runs `disable()`.

### Defaults

New installs start with every extension disabled. Upgrades enable an extension automatically if data already exists:

| Extension | Enabled on upgrade when |
| --- | --- |
| `documents` | Document storage is configured or at least one document exists. |
| `vault` | Vaultwarden is configured or at least one published item exists. |
| `conn` | At least one T3 server or profile is registered. |

## Why it works this way

Giving each optional feature its own directory and typed core interfaces keeps core clean, makes every contract explicit, and lets `ExtensionBoundaryTest` enforce the boundary automatically without packaging extensions as separate Composer packages. Always loading routes and migrations keeps schemas and generated OpenAPI/MCP specs independent of runtime switches.
