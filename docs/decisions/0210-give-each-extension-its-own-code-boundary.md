---
title: "ADR 0210: Give each extension its own code boundary"
sidebarTitle: "0210 Extension boundaries"
description: "In progress. Each Gateway extension lives in its own directory with one service provider, contributes to core through tagged plug-in points, and core never names it. Project Documents moved first; Vault and Conn moved next."
---

# ADR 0210: Give each extension its own code boundary

Each Gateway extension lives in `apps/gateway/extensions/<name>/` with its own namespace and one service provider. Core offers small plug-in interfaces, and an extension contributes to them through container tags. Core code never names an extension. A Pest architecture test enforces that rule. Project Documents moved first; Vault and Conn moved next.

## Status

In progress.

Principle: [lean](/mission#principles). An optional feature costs core nothing when it is off, and Orbit can delete it as one directory when nothing uses it. Both hold only when core does not import it.

## Context

Today an [extension](/cli/extension) is only a switch. `ExtensionStore` holds a fixed list, `['tasks', 'proxycli']`, and the request middleware, the MCP server, the CLI, and the web app each repeat that list or a route name prefix. The code of an optional feature lives in the core namespaces next to everything else.

Project Documents shows the cost. Its models, actions, controllers, requests, data classes, console commands, migrations, and schedule entries sit in `App\Models`, `App\Actions\ProjectDocuments`, `App\Console\Commands`, and the other core folders. Core calls it directly: `DestroyProjectAction` locks and removes a Project's documents, and the Gateway release handoff binds `ActionGatewayDocumentCleanup`. The same pattern holds for Conn, Vault, and ProxyCli. Core also names ProxyCli in its Caddy site sources, private DNS records, reserved hostnames, and firewall rules. Nobody can tell which code belongs to an optional feature, and nobody can remove one without a search through core.

The Gateway caches its routes, and migrations must give the same schema on every install. So an extension cannot be loaded or skipped based on a database switch.

## Decision

Each extension owns one directory and one provider. Core offers typed plug-in points, a Pest architecture test keeps core free of extension code, and the runtime switch stays as it is.

### One directory and one provider per extension

An extension lives in `apps/gateway/extensions/<name>/`:

| Path | Holds |
| --- | --- |
| `src/` | PHP code under the namespace `Orbit\Extensions\<Name>\`: its provider, models, actions, data classes, controllers, form requests, console commands, and infrastructure. |
| `database/migrations/` | Its migrations. They keep their file names, so the `migrations` table does not run them again. |
| `routes/api.php` | Its API routes. |

The Gateway's `composer.json` maps the namespace with one PSR-4 entry for each extension. Tests stay under `apps/gateway/tests`, in `Feature/Extensions/<Name>/` and `Unit/Extensions/<Name>/`, so Pest, test-impact analysis, and CI selection keep one configuration.

Each extension has exactly one service provider. It extends `App\Providers\ExtensionServiceProvider` and implements `App\Domain\Extensions\Extension`:

| Method | Purpose |
| --- | --- |
| `name()` | The switch slug, such as `documents`. |
| `description()` | One sentence that describes the extension. |
| `dependencies()` | The slugs of the extensions that must be enabled first. |
| `enable()` | Setup before the switch turns on. The default does nothing. |
| `disable()` | Teardown after the switch turns off. The default does nothing. |

The provider registers its routes, migrations, console commands, schedule entries, and plug-in contributions. `bootstrap/providers.php` lists it. `ExtensionRegistry` builds the list of known extensions from the registered providers that implement `Extension`. No other list of slugs exists in the Gateway.

Tasks and ProxyCli stay in core until their planned move. Their providers in `App\Providers` implement `Extension`, so they keep their switches. Tasks moves its realtime broadcast on a switch change into its `enable()` and `disable()` hooks.

### Plug-in points

Core defines an interface for each place where an extension adds behavior. The extension's provider tags its implementation with the interface name, and core resolves every tagged implementation. This pull request adds the two points that Documents needs:

| Interface | Core caller | What an extension does |
| --- | --- | --- |
| `App\Domain\Projects\ProjectRemovalHook` | `DestroyProjectAction` | `lock()` runs first in the removal transaction, before the guards. `remove()` runs after every guard passed, before the Project row is deleted. It may refuse with a `ResourceOperationException`. |
| `App\Domain\GatewayReleases\GatewayReleaseCleanup` | `GatewayCleanupHandoff` | Reports its cleanup state and generation, reconciles, and resumes after the release handoff restarts the scheduler. With no contributions, the handoff reports `skipped`. |

The Conn move adds a third point:

| Interface | Core caller | What an extension does |
| --- | --- | --- |
| `App\Domain\Activity\ActivityInputRedaction` | `RecordCommandActivity` | Names the commands whose input it decides, and returns the part of the input that Activity may keep. |

Core sanitizes what the redaction returns like any other input. The redaction runs whether or not the switch is on, because Activity records a refused request too.

Each extension that moves after Documents adds points the same way: Caddy site sources, private DNS records, reserved hostnames, footprint artifacts, firewall rules, ownership guards, Activity redaction, and schedule entries. Each point is a narrow typed interface. A generic event bus or hook registry is not part of the design.

### The boundary is enforced

A Pest architecture test fails when any class under `App\` uses a class under `Orbit\Extensions\`. An extension may use core models, actions, data classes, and plug-in interfaces. Bootstrap files, configuration, and tests are not under `App\`, so `bootstrap/providers.php` can list extension providers.

### The runtime switch stays

The boundary is about who owns code. It does not decide what loads.

- Routes, migrations, console commands, and MCP tools are always registered.
- An extension's route group adds `RequireEnabledExtension:<name>`. While the switch is off, those operations refuse with HTTP 409 `extension.disabled`. Tasks and ProxyCli use the same middleware parameter, so the middleware no longer guesses an extension from a route name.
- `bin/docs-openapi` records the slug as `x-orbit-extension`, and the MCP server filters tools on it, as before.
- Background work that finishes recorded work keeps running while the switch is off. For Documents that is the cleanup worker, the probe reconciliation, the release cleanup handoff, and the removal of a Project's documents. The switch hides operations; it does not abandon bytes that are already scheduled for deletion.

### Enable and disable

Enabling an extension refuses with HTTP 409 `extension.dependency_disabled` while one of its dependencies is off. Disabling refuses with HTTP 409 `extension.required` while an enabled extension depends on it. The Gateway runs `enable()` first and writes the switch only after the hook returns. A failed enable leaves the switch off: when `enable()` throws, the switch keeps its earlier state and the API returns the hook's error. So the hook runs while the switch is still off and must not rely on it. `disable()` runs after the switch turns off. Both run in the same request. A hook must be safe to repeat, because enabling an enabled extension runs it again. A hook that needs fleet resources still belongs in the extension's own setup command, as with `proxycli:setup`.

Documents declares no dependencies and needs neither hook.

### Documents moves now

All Project Documents code moves to `extensions/documents` under `Orbit\Extensions\Documents\`, with the switch `documents`. Table names, API paths, route names, MCP tool names, CLI command names, artisan command names, and error codes stay the same. No data moves.

A migration turns `documents` on for an install that already has Documents state: a storage configuration or at least one document entry. Every other install starts with `documents` off, like the other extensions. A current user keeps Documents without action, and a new install does not carry a feature it has not asked for.

The CLI hides the `project:document:*` and `project:document-storage:*` commands while `documents` is off, as it does for the other extensions. The web app keeps the Documents page in `apps/web/src/extensions/documents/` and hides the Project Documents section and route while the switch is off.

### Vault and Conn move next

All Vault code moves to `extensions/vault` under `Orbit\Extensions\Vault\`, with the switch `vault`. All Conn code and its three migrations move to `extensions/conn` under `Orbit\Extensions\Conn\`, with the switch `conn`. Table names, API paths, route names, MCP tool names, CLI command names, artisan command names, and error codes stay the same. No data moves. Neither declares a dependency or needs a hook.

Core named Vault only in a container binding, its routes, and its schedule entry, and Conn only in a container binding, its routes, and Activity. The bindings, routes, and schedule entry move into each provider. The Conn redaction moves behind `ActivityInputRedaction`. Core named neither in its Caddy sites, private DNS, or reserved hostnames, so those points are not needed yet. No other feature calls Vault or Conn: the Vaultwarden sync reads the Database records itself, and Node removal reaches the Conn tables through foreign keys.

The migration that creates `vault_items` stays in core. The same file adds the `database_users.password` column, which core writes when it creates a Database user, and a migration cannot be split without running again.

`orbit:vault-sync` runs only while `vault` is on. Publishing is the feature itself, not the end of recorded work like the Documents cleanup. The first run after the switch turns on again creates, rewrites, and deletes whatever changed meanwhile.

A migration turns `vault` on for an install with a stored Vaultwarden setup or a published item, and `conn` on for an install with a registered T3 server or a profile. Every other install starts with them off. The CLI hides the `vault:*` commands while `vault` is off and sends a direct call without discovery, as for Documents. Conn has no CLI commands and neither has web pages.

### Planned order

1. Documents, in this pull request.
2. Vault and Conn, in the next pull request. They needed only the Activity redaction point.
3. ProxyCli, only after a separate plan. Tasks depends on it: `TaskVmRuntime` imports ProxyCli, and Tasks must keep working through the move.
4. Analytics stays core while `RoleName` is a core enum.
5. Tasks gets a boundary only after its scheduler is simplified. Tasks is an essential feature and stays.

This record stays in progress until the last planned extension has moved. The pull request that moves it absorbs this record into [Extensions](/reference/extensions).

### Not built

Runtime install, a Composer package for each extension, separate processes, and a versioned plugin API are not part of this decision.

## Rejected alternatives

- Load an extension's routes and migrations only while its switch is on: the route cache, the OpenAPI document, and the generated MCP tools would change with database state, and a schema would differ between installs.
- A Composer package for each extension, through path repositories: each move would touch the lock file and the tooling baseline, and nothing outside this repository installs an extension.
- Keep the extension code in core folders and only list it in a provider: the boundary would exist only in a reviewer's head, and an architecture test cannot check it.
- One generic event bus for every hook: a typed interface states what an extension may change and what core expects back. Events would hide that contract and the order of calls.
- Tests inside each extension directory: Pest, test-impact analysis, and CI select tests from one tree. A second tree would need its own configuration in each tool.
- Turn `documents` on for every install, new ones included: a new install would carry an optional feature by default, unlike every other extension.
- Turn `documents` off for every install: a current user would lose the Documents pages and commands with no warning.
- Keep `orbit:vault-sync` running while `vault` is off: the Gateway would keep sending credentials to Vaultwarden after the operator turned the feature off.
- Move the `vault_items` migration into the extension: the same file adds a core column, and deleting the extension would remove it.

## Consequences

- An extension can be found, reviewed, and deleted as one directory plus its tests and one provider line.
- Core states what an extension may change through plug-in interfaces, and the architecture test keeps core from reaching back.
- Two Documents footprints stay in core until their plug-in points exist: the PHP-FPM startup hook that runs `project-documents:cleanup:invalidate`, and `ValidateDocumentPostSize`, which keeps document request bodies exact. They name an artisan command and a URL path, not an extension class.
- `bin/docs-openapi` also reads controllers, form requests, and data classes under `apps/gateway/extensions/*/src`.
- The CLI and `bin/docs-openapi` keep their own slug lists. The CLI shows every slug as `UNKNOWN` when the Gateway cannot answer, and the OpenAPI document is generated without a database. A new extension updates both lists.
- While `documents` is off, its background cleanup still runs and still needs its storage credentials.
- While `vault` is off, a published item stays as it was. A credential that changed or a Database user that was removed meanwhile is fixed on the first sync after the switch turns on.
- While `conn` is off, a T3 server cannot register and a device cannot read its profile. The records stay.

## Affects

- Components: apps/gateway, apps/cli, apps/web, apps/docs
- ADRs: none
- Detail: [Extensions](/reference/extensions)
- Verify: `tests/Unit/Architecture/ExtensionBoundaryTest.php`, `tests/Feature/Api/ExtensionsTest.php`, `tests/Feature/Domain/Activity/ActivityInputRedactionTest.php`, the tests under `tests/Feature/Extensions/{Documents,Vault,Conn}`, and `composer test:affected` in apps/gateway and apps/cli
