---
title: "ADR 0210: Give each extension its own code boundary"
sidebarTitle: "0210 Extension boundaries"
description: "In progress. Each Gateway extension has one provider and typed contributions. Analytics moves only after explicit Process ownership and acceptance that serving and data stay intact."
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

ADR 0212 step 3 moves ProxyCli to `extensions/ai-router` and renames it AI router, so core no longer owns its provider or code.

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

`input()` receives the request input as a lazy closure. A redaction that keeps nothing never calls it, so the body is never parsed. Core sanitizes what the redaction returns like any other input. The redaction runs whether or not the switch is on, because Activity records a refused request too.

Two more points move the last Documents footprints out of core:

| Interface | Core caller | What an extension does |
| --- | --- | --- |
| `App\Domain\Gateway\GatewayServiceStartHook` | `NativeGatewayFpmConverger`, bound once for web convergence and the release handoff | Names a program that ends in `-start`, an `orbit-*.conf` drop-in, and a one-line description, and renders the program. Core runs the program at each PHP-FPM start and before each pool reload. |
| `App\Domain\Gateway\ExactRequestBody` | `ValidateRequestBodySize` | Matches request paths whose body stays exact. Core skips PHP's `post_max_size` check, string trimming, and empty-string conversion for them. The route bounds the body after authorization. |

Core checks each start hook before it changes anything, then installs the program and its drop-in. Core keeps the MCP envelopes exact itself. Documents contributes its startup hook and its document routes. The hook and its drop-in keep their paths and bytes. Core records the hooks it installed in a manifest and removes only what the manifest lists. The first run on a Gateway without a manifest adopts the Documents files and removes nothing.

Each extension that moves after Documents adds points the same way: Caddy site sources, private hostnames with optional DNS addresses, footprint artifacts, firewall rules, ownership guards, Activity redaction, and schedule entries. Each point is a narrow typed interface. A generic event bus or hook registry is not part of the design.

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

`orbit:vault-sync` runs only while `vault` is on; the maintainer decided this on the pull request that moved Vault. Publishing is the feature itself, not the end of recorded work like the Documents cleanup. The first run after the switch turns on again creates, rewrites, and deletes whatever changed meanwhile.

A migration turns `vault` on for an install with a stored Vaultwarden setup or a published item, and `conn` on for an install with a registered T3 server or a profile. Every other install starts with them off. The CLI hides the `vault:*` commands while `vault` is off and sends a direct call without discovery, as for Documents. Conn has no CLI commands and neither has web pages.

### Planned order

1. Documents, in this pull request.
2. Vault and Conn, in the next pull request. They needed only the Activity redaction point.
3. ProxyCli, only after a separate plan. Tasks depends on it: `TaskVmRuntime` imports ProxyCli, and Tasks must keep working through the move.
4. Analytics moves through the [serial transition below](#analytics-transition-contract), with explicit Process ownership first. Nick authorized this proposal despite the earlier deferral while `RoleName` is core. The enum remains a persisted identity, not a reason to keep the implementation in core.
5. Tasks gets a boundary only after its scheduler is simplified. Tasks is an essential feature and stays.

This record stays in progress until the last planned extension has moved. The pull request that moves it absorbs this record into [Extensions](/reference/extensions).

### Analytics transition contract

This is a proposal, not an extraction or a fleet change. Analytics is still core, has no extension switch, and keeps serving as described in [Analytics role](/reference/analytics). Group #1777 changes documentation only. Separate native task groups implement and independently review each step in order. The parent owns exact-head merge, main CI, deployment, rollout, tick, and post-deploy acceptance. This proposal does not authorize a live Node change, acquire a topology, enable an extension, or adopt a Process.

#### Source and investigation boundary

The proposal was checked against group start `592e88fdffe1041a2cafc19814e9b3c2ae8e1661`, also the `origin/main` merge base. The full Argon round-one report, `argon-20261018-r1.md`, and corrected parent triage, `argon-20261018-r1-triage.md`, were read together. They concern `560418c0355f42f91a95378180bbd242abf65242`, not this head. The corrected triage treats findings as investigation inputs, not approved deletions, interface counts or runtime acceptance. The Tasks and AI router findings stay with their separate plans; they do not authorize cleanup in this Analytics transition. Before an implementation group starts, read its current main, contributor instructions, this contract, and the latest complete complexity review and corrected triage; repeat the caller audit below.

Paths in this table are relative to `apps/gateway/`. They name the current production callers, not proposed interfaces inferred from a seam list.

| Current source | Finding and boundary to preserve |
| --- | --- |
| `app/Domain/Nodes/{RoleName,RoleRegistry}.php`, `app/Models/NodeRole.php` | `analytics` is a persisted enum value. The role is singleton, conflicts with Gateway, cannot be assigned during provisioning or relocated, and can share a database Node. |
| `app/Http/Requests/Nodes/AddNodeRoleRequest.php`, `app/Actions/Nodes/AddNodeRoleAction.php`, `app/Infrastructure/Nodes/Roles/{NativeRoleBaselineConverger,AnalyticsRoleBaseline}.php` | Core parses Analytics storage settings, validates them before assignment, stores them before convergence, then dispatches directly to the baseline. Role and Node locks and failure state belong to core. |
| `app/Infrastructure/Analytics/NativePlausibleRuntimeLifecycle.php`, `app/Actions/Processes/{CreateProcessAction,DestroyProcessAction}.php` | Analytics catches `process.name_taken`, removes the named Process with the owning-role bypass, and recreates it. Remove and forget also select by name. Matching specifications do not prove ownership. |
| `app/Domain/Analytics/AnalyticsProcessOwnership.php`, `app/Providers/ApplicationServiceProvider.php` | The guard protects named Plausible and the two storage IDs. Keep it, the independent Database server/AI router guards, and the Process runtime lease. |
| `app/Infrastructure/Nodes/EloquentNodeRoleDependencyInspector.php`, `app/Actions/Nodes/RemoveNodeRoleAction.php`, `app/Infrastructure/Nodes/NativeNodeRoleDependentCleaner.php` | The inspector returns four empty lists. Removal calls it for preview, claim and finalization; this does not prove the cleaner, consent, offline handling, recovery, guards or baseline are unused. |
| `app/Infrastructure/Analytics/NativeAnalyticsClickhouseConfigurationManager.php`, `app/Domain/Analytics/{AnalyticsStorageProcessGuard,AnalyticsStorageConnection,PlausibleProcess}.php` | Operator-created Docker Processes hold storage on database Nodes. Convergence can replace/restart ClickHouse; Plausible startup runs database migrations. Adoption must call neither path. |
| `app/Infrastructure/Caddy/Build/Sources/AnalyticsCaddySiteSource.php`, `app/Infrastructure/Analytics/{NativeAnalyticsPublicationManager,AnalyticsFootprint,AnalyticsCertificatePublisher}.php` | Publication waits for health, issues/publishes the certificate, records slug `analytics`, builds Caddy and publishes DNS. Keep source, scope, directories and markers; allocate nothing anew. |
| `app/Infrastructure/{AppDev/DevelopmentSiteRepository,AppDev/DevelopmentDnsConfigRenderer,Certificates/OpenSslGatewayCertificateIssuer,Firewall/NodeFirewallRuleCatalog}.php` | Core derives the tracking upstream, private dashboard DNS, Analytics certificate scope and local-storage firewall rules directly from Analytics classes. Moving only the provider would leave these dependencies behind. |
| `app/Actions/Routes/{ConvergeAnalyticsTrackingPlacementAction,ConvergeRouteAction}.php`, `app/Domain/Routes/{RouteMutationReconciler,RouteRemovalGuard}.php` | Tracking placement has certificate-before-build, dual serving, database cutover, DNS publication, answer-expiry wait and withdrawal steps. Role removal refuses existing tracking Routes. Preserve the stored transition and retry semantics. |
| `app/Models/{Route,RouteAnalyticsTracking}.php`, `app/Domain/Routes/RouteKind.php`, `database/migrations/*analytics_tracking*` | `analytics_tracking`, the relationship, API representation and database triggers are stored/public vocabulary. These do not justify a new role or Route framework or a data rewrite. Mixed Route migrations stay core. |

Unlike the report's constants sketch, `AnalyticsTrackingUpstream` selects a role Node by lifecycle state and WireGuard address. Preserve its active/provisioning behavior behind the typed contribution; do not replace it with a static upstream.

There is no class named `ProcessOwnershipRegistry` in this baseline's Gateway source or tests. Do not create or delete one on the strength of the older report. If current main has one when implementation starts, inspect its bindings and callers and preserve its guarantees. At this baseline those guarantees are split between tagged `ProcessRemovalGuard` contributions, owning-feature removal and runtime Process ID markers. The empty inspector and its surrounding deletion candidate are out of this extraction's scope; any deletion needs a separate caller, role-removal and failure-recovery proof.

#### Small typed boundary, not an extensible role system

Keep `RoleName::Analytics = 'analytics'`, existing role casts, static `RoleDefinition` safety metadata, package/service/Doctor metadata and persisted Route vocabulary in core. These are bounded identity and safety contracts, not imports of extension behavior. No string replacement for all roles, dynamic role registry, event bus, versioned plug-in API, or generic Route-kind framework is needed. This qualifies the general rule that core never names an extension: core can retain its existing stored identifiers and public protocol shape, but cannot resolve Analytics implementation classes or read Analytics settings itself.

Add one tagged, core-owned `ContributedNodeRole` contract that extends the existing `RoleBaseline`. It identifies its `RoleName` and contributes only assignment input rules, validation and settings storage in addition to the existing `converge`, `remove` and `removeUnreachable` lifecycle. Rule keys define the accepted extra input keys; the contribution receives validated scalar input, never an arbitrary service context or a new persisted options bag. Validation runs before writes or remote work; settings storage runs under the existing Node role lock before baseline convergence. Core's add action and request no longer import `AnalyticsRoleSettings` or its repository. The same contribution supplies the baseline to the existing dispatcher. Its assignment gate refuses `extension.disabled`; internal reconciliation of already recorded state does not use that gate. For an extension-provided baseline with no matching contribution, a mutation refuses as unsupported before writing state; it must not fall back to the old core implementation.

The extension contributes this one implementation for the existing Analytics enum value. All other role baselines keep their current dispatch and policy. The request's strict JSON, positive-integer validation, prohibited fields on other roles, API schema types, CLI flags and error behavior remain testable contracts. Generated OpenAPI must still describe `postgres_process_id` and `clickhouse_process_id` correctly; do not sacrifice the schema because rules moved out of the request file.

Reuse `ProcessRemovalGuard`, `ContributedCaddySiteSource`, `ContributedPrivateHostnames` and the existing footprint contribution/digest machinery. The dashboard keeps source and certificate slug `analytics` when it changes from core to contributed; update core-reserved source/slug lists in the same slice so there is one producer and no collision. Certificate issuance accepts an explicit scope defined by core instead of importing `AnalyticsHostname` to choose its paths. All existing callers keep their current scopes.

Two other core callers need narrow typed boundaries, not the review's fixed number of seams:

- The firewall catalog receives a tagged role-rule contribution, keyed by `RoleName`, returning existing `UfwManagedRule` values for desired and owned rules. Analytics supplies its HTTPS and local-storage rules; the core manager keeps execution and removal. Rules keep their comments, addresses, interfaces and ports. This contribution runs while disabled for recorded roles and cleanup. It does not add a general firewall-policy framework.
- Core receives a tagged `InstanceTrackingPublication` contribution for the existing tracking Route protocol: resolve the serving upstream, cut over to a `RoutePlacement`, report pending withdrawal and withdraw after DNS answer expiry. Move the Analytics upstream lookup and placement implementation behind it. Core keeps shared Route storage, fixed two-path rendering and placement orchestration. It still refuses direct mutations. The provider supplies this contract even while off; an off switch must not make a Caddy/DNS rebuild omit stored tracking hosts. Tests cover production wiring at `DevelopmentSiteRepository` and `ConvergeRouteAction`, not only a fake implementation.

A small tagged `NodeRoleRemovalGuard::assertRemovable(Node, RoleName)` is justified by removal, not by the empty inspector. Analytics uses this guard to refuse role removal while tracking hosts exist. It also refuses removal of a database role while either referenced storage Process belongs to that Node. Run the guard before claiming removal and again under the existing role-removal lock before teardown. Force and offline flags do not bypass it. Keep other core Route and role guards unchanged. Do not synthesize Process dependents for every Process on a database Node.

The extension owns its provider, Analytics actions/controllers/requests/data, baseline, runtime, settings, secrets, stats driver, dashboard source, publication, configuration resources and the tracking implementation. It lives under `extensions/analytics`, namespace `Orbit\Extensions\Analytics`, slug `analytics`. Existing HTTP paths, route names, MCP names, CLI commands, persisted settings keys and errors keep their names. Tests move under the established extension test directories. The architecture test must reject a core import of Analytics implementation code; no allowlist for its former namespaces is acceptable.

#### Explicit Process ownership before extraction

Use a nullable persisted `owning_node_role_id` reference on the Process to distinguish a role-owned runtime from an ordinary Node-owned Process. `owner_type`/`owner_id` still identify the Node; the new reference identifies the owning assignment, not its database dependencies. This metadata is internal, absent from public Process input and output. The reference must prevent deleting an assignment while it still owns a Process, not cascade-delete the Process or silently clear its claim. Ordinary Processes have no role claim. PostgreSQL and ClickHouse remain operator-owned and are referenced only through the existing settings. Do not claim every Process on the role Node or every Process named `plausible`.

A fresh role runtime is created and claimed together in the Process admission transaction, before remote convergence. Core accepts the typed owning assignment only on an internal creation path, not through public Process input. If a Process already occupies the name without that exact claim, return a conflict without reconverging, marking, replacing or deleting it, even if its specification matches. Once claimed, updates, role removal and unreachable forget select the Process ID and verify the fresh claim and Node under the existing Process runtime lease. Losing a claim fails closed; it never triggers a name lookup or adoption. The owning-role guard bypass is not ownership evidence: verify that the requested assignment owns that exact fresh Process before allowing Analytics to use it. Preserve the independent Database server and AI router ownership paths and all ordinary Process behavior.

Existing installs need an explicit metadata-only adoption operation, `analytics:adopt --process=ID`, available in the ownership slice before the code move. It records the supplied Process ID against the existing assignment; it does not infer authority from the name. Native acceptance supplies the reviewed role-to-Process mapping. The action checks the Node and assignment, lack of another claim, Docker runtime, expected pinned image/command, complete stored specification, storage URLs derived from the two recorded storage IDs, stored secret and WireGuard binding. Validate the runtime's exact Orbit Process ID marker and the observed serving container too. A matching specification alone is insufficient; the explicit ID is the operator's ownership assertion. Never print credentials as evidence.

Adoption takes the existing role lock and Process lease, rechecks before the metadata write, and repeats safely for the same mapping. Wrong Node, stale ID, foreign claim, mismatched runtime/specification, missing secret/settings or a different runtime marker refuses without any write or runtime call. If the old stored working directory differs from today's default, preserve it; adopting does not normalize specifications. No create, update, start, restart, remove, ClickHouse configuration, secret generation, certificate issuance, Caddy reload, DNS publication or Plausible database migration runs during adoption. Stopped Processes stay stopped. A partially failed role is not repaired by adoption; inventory it and use a separately reviewed recovery before the extraction gate.

Do not auto-adopt on deploy, `process.name_taken`, enable or ordinary convergence. An existing assignment without a proved claim keeps its deployed serving untouched, but attempted runtime mutation fails with an actionable adoption-required conflict. Migration adds metadata only. Deployment of the ownership slice must not trigger old name-based convergence before adoption. The parent records the mapping and adoption result before allowing the extraction rollout.

#### State, data and serving continuity

| Resource | Required continuity across adoption, extraction, disable and rollback |
| --- | --- |
| Role and settings | Keep assignment ID, `analytics`, status, Node and the Node-scoped `analytics.postgres_process_id`, `analytics.clickhouse_process_id`, `analytics.plausible_version` and protected `analytics.secret_key_base`. Do not regenerate secrets or choose the configured default version over the stored pin. |
| Runtime | Keep Process/container IDs, start time, full specification, desired state, ports and runtime markers. Extraction and the switch do not update versions or reconcile Processes. |
| Storage | Keep database Process IDs, credentials, actual data paths, volumes, writable layers and working directories. PostgreSQL holds accounts/sites/settings; ClickHouse holds events. |
| Files and uploads | Keep the four `/etc/orbit/analytics/clickhouse/` files and their read-only mounts byte-for-byte. Preserve all Instance/Project uploads and deployed container data. |
| Credentials | Keep the protected Gateway-scoped `analytics.stats_api_key`, Plausible accounts/sites/keys and unrelated model keys/accounts/origins. Never expose environment values in logs, diffs, evidence or API output. |
| Dashboard | Keep `analytics.orbit`, its WireGuard-only address and port 8000 backend, Caddy source `analytics`, certificate slug/scope `analytics`, certificate-current/version directories, ownership markers and existing certificate/key bytes. A source-code move alone neither renews nor withdraws a certificate. |
| Tracking | Keep Route IDs, kind, domain, publication, placements, links, transition steps, sites, certificates and DNS. Preserve the two-path proxy, active/provisioning upstream selection, dual serving and DNS expiry. |
| Other resources | Preserve ordinary Processes, unrelated databases/uploads, Caddy sites, Routes, certificates, active operations, task turns/leases/checks and recovery evidence. No sweeping Node convergence or cleanup is authorized by a directory move. |

An explicit `analytics:update` can still replace an owned runtime; that operation is not part of extraction acceptance. Do not move, recreate, initialize, normalize or prune either database, even if a mount looks wrong. The Plausible specification declares no volumes and this code has no Analytics upload store; that does not prove writable layers or deployed custom mounts contain no data. Inventory actual data and upload paths for all three containers before rollout. Any required data-path repair is a separate migration. Tracking hosts still proxy only `/js/*` and `/api/event`; other paths return 404. The switch never becomes a serving predicate.

The extraction migration turns `analytics` on only when existing state shows use: an Analytics assignment in any lifecycle state, an Analytics settings/secret/stats-key entry, or a tracking Route/link. It does not infer use from a Process name, rewrite that state or call hooks. A fresh install has it off. Failed/partial state keeps management discoverable but does not become healthy by migration. An explicitly stored switch decision wins on retry; redeployment cannot turn a deliberately disabled install back on.

Routes, migrations, commands and contributions are always registered. Analytics declares no extension dependencies: the core database role and its Processes are resources, not a fictitious database extension. Assignment validates them as today; the new removal guard protects the required database role. No current extension is declared to depend on Analytics. Existing `extension.dependency_disabled` and `extension.required` checks apply if a real dependency is added; do not add Tasks or AI router to this dependency graph.

Enable is repeatable, metadata-only and does not assign a role, adopt, restart, migrate or provision storage. Disable is also metadata-only: do not call baseline removal from the post-switch `disable()` hook. While off, Analytics API operations return `extension.disabled`, MCP discovery and CLI/web discovery hide its management/stats surface, and a direct CLI request still reaches the authoritative API refusal. The generic role-add endpoint must also refuse new or explicit Analytics convergence while off. The web Instance page omits the stats read rather than polling a disabled endpoint or showing zero visits.

While off, existing Plausible, tracking traffic and databases keep running. Contributions for stored-state Caddy/DNS/footprint rebuilds, recorded placement completion, ownership/removal guards and failure cleanup stay installed and active. Generic Process logs/restart retain their established behavior; disable does not stop containers. Explicit core role removal remains possible with normal consent and guards, but tracking hosts block it, so re-enable and disable Instance analytics first. Removal then deletes only the claimed Plausible runtime and existing role publication/settings as today; it never deletes storage Processes, their data, ClickHouse mounts or uploads. Disable is not uninstall, purge or credential revocation. Deleting the provider/directory while owned state exists is unsupported.

#### Serial native implementation and review gates

Each row is a separately reviewed native task group with its own docs-first subtask, affected tests and declared topology. Do not activate rows in parallel or use the proposal group to reserve guests. The parent keeps this work behind its serial priorities and revalidates source and the latest review before activation.

1. **Ownership group.** Fix explicit ownership in core, with additive schema and metadata-only adoption. Remove all Analytics name-based reuse/takeover/removal/forget paths. Do not introduce the extension switch yet.
   - Regression tests fail on the start commit for unrelated same-name Processes, including an exact-spec match, wrong-claim deletion and adoption side effects.
   - The independent reviewer verifies success, refusal, retries, reachable removal, failed removal/recovery and unreachable forget on the group's allocated topology.
   - The parent inventories and explicitly adopts the serving runtime without a restart before proceeding.
2. **Extraction group.** Introduce the narrow contribution boundaries above and move Analytics to its provider in one coherent slice. Add state-based switch migration, API/client gates and disabled serving behavior together. Preserve public contracts, migration filenames and mixed core migrations; there is no dual core/extension implementation.
   - Architecture tests prove core independence. Tests also verify each provider's production wiring.
   - Role assignment/storage validation, removal guards, Caddy/DNS/firewall/certificate identities, OpenAPI, MCP, CLI/web gates and stored placement recovery pass on and off.
   - An upgrade from the adopted preceding release preserves service and data. The parent rolls out only after this proof.
3. **Completion and parent acceptance.** The extraction group's last implementation slice updates the owning references to the shipped behavior and marks deployment acceptance as pending. It does not wait for permission to mutate live Nodes, and there is no check-only task group. The parent records exact reviewed/deployed/main commits, CI, rollout/tick and the live observations below after normal deployment. Until those pass, report extraction as unverified, not enabled or migrated. The parent then absorbs this Analytics proposal into the references as completed behavior; ADR 0210 as a whole stays in progress while its other planned moves remain.

The affected suites include `AnalyticsRoleBaselineTest`, `NativeAnalyticsRoleSettingsRepositoryTest`, `NativeAnalyticsClickhouseConfigurationManagerTest`, `NativeAnalyticsPublicationManagerTest`, `AnalyticsTrackingRouteProjectionTest`, Process create/destroy and ownership tests, `RemoveNodeRoleActionTest`, `NodeRoleDependencyInspectorTest`, `LegacyRuntimeConsumerRemovalTest`, `RoleCatalogContractsTest`, extension boundary/API tests, route placement/DNS/certificate tests and the CLI/web tests for discovery and the stats panel. Move tests with the feature without losing selection. Add the missing adoption, collision and switch-off cases; existing happy paths are not ownership proof. Run affected tests with `ORBIT_CHECK_PROCESSES=1`, one heavy command at a time. Orbit runs the Project check at handoff.

A fresh independent reviewer checks the diff against the [plug-in checklist](/reference/extensions#add-a-plug-in-point), first inspecting every owning-feature bypass and name lookup. The reviewer proves the same-name ordinary Process is unchanged by assignment, convergence, update, reachable removal and unreachable forget; storage removal remains guarded, and deleting the database role is refused. Exercise failures before and after ownership metadata, publication and placement cutover, then retry using persisted state. Keep the role/Process ownership and removal guards on while disabled. Record reviewed commit, allocated environment, commands, results and limitations. Docs review is source/contract review only; it cannot count as native or live acceptance.

#### Deployment, rollback and live acceptance

The ownership release is the minimum rollback floor for extraction. A release older than that still has the name-based hazard and is not a supported rollback target. Additive ownership metadata must be readable/ignorable by the immediately preceding ownership release. Keep persisted enum values, tables, settings, Process IDs, migration filenames, stored specifications and public request/response shapes stable. Do not rename queued payload classes blindly: inventory durable jobs/operations before the namespace move and drain or complete affected Analytics work before activation using existing release controls. Never drain unrelated active task turns or erase operations, leases or checks. Do not retain a class alias, second implementation or legacy branch as a substitute for a release gate.

Rollback switches the Gateway code back to the reviewed ownership release through normal release controls; it leaves additive metadata and database schema in place and does not run migration `down`, role removal or Plausible downgrade. No Plausible version, secret, mount, data path or certificate changes are authorized by extraction, so rollback must not require a data restore. A failed enable leaves the switch off. A failed adoption leaves runtime/state untouched; fix the inventory and retry. A failed extraction deployment stops activation and keeps existing publication, then retries or rolls back without tearing down the role. If metadata is inconsistent, refuse mutation and repair through the reviewed ownership operation; do not clear claims or guess from a name.

The preceding ownership release has no extension switch. Rolling back to it restores core management availability, including after an extraction-era disable, while serving remains unchanged. Record that deliberate control-plane difference in the rollback evidence; it is not a reason to remove the serving resources. When the extracted release returns, retain the stored switch decision. Review both the forward upgrade and this rollback on allocated topology before any parent rollout.

The parent performs live acceptance after the exact reviewed head passes main CI and normal deployment/rollout/tick. No task implementer changes a live Node. Capture a redacted before/after inventory and a time window spanning activation, not merely a final HTTP 200:

1. Record role/settings identities and protected-value equality, Process and container IDs/start times, desired states, complete specification equality, volume/data/upload paths, existing database/site/event samples, Route/placement IDs, DNS answers, Caddy ownership and certificate fingerprints. Include an ordinary Process control. Do not dump secrets.
2. From existing authorized peers, continuously check the private dashboard and tracking script while activation runs; send uniquely identified test events only to a disposable site authorized for that implementation group's acceptance. Verify event arrival in ClickHouse/Plausible and the Instance stats read, with no failed requests in the observed window. Do not send synthetic traffic to a production site without operator authorization. If no safe live test site exists, record that gap and do not claim end-to-end event acceptance.
3. Verify the dashboard remains private, tracking-only hosts return 404 outside their two paths, storage ports remain WireGuard-bound, DNS/certificate identities are unchanged and no adoption/extraction-triggered container restart, database migration, ClickHouse configuration change or credential rotation occurred.
4. On allocated topology, disable/re-enable and rebuild Caddy/DNS while off; tracking and dashboard serving continue, API/discovery gates change, ownership/database guards remain effective and the same containers and data survive. Resume an existing placement transition, and prove the old placement survives until DNS expiry. Exercise rollback and return to the extracted release with stored switch intent retained. Live checks of these mutations require a separately authorized parent acceptance window; absent that, report topology proof and the live limitation separately.
5. Confirm unrelated Processes, Instances/uploads, Routes/certificates, task turns/leases/checks, active operations, model credentials/accounts/origins and database samples are unchanged. The evidence states what was observed and for how long, not an unmeasured promise of zero downtime.

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

- An extension can be found, reviewed, and deleted as one directory plus its tests and one provider line. The next Gateway convergence removes the start hooks of a deleted extension.
- Core states what an extension may change through plug-in interfaces, and the architecture test keeps core from reaching back.
- Core no longer installs the Documents PHP-FPM startup hook or matches document routes. Documents contributes the hook, which runs `project-documents:cleanup:invalidate`, through `GatewayServiceStartHook`, and its exact request bodies through `ExactRequestBody`.
- Documents contributes its Activity rule through `ActivityInputRedaction`: it matches `project:document:*` by route name and never calls the input closure, so Activity never parses a document body.
- `bin/docs-openapi` also reads controllers, form requests, and data classes under `apps/gateway/extensions/*/src`.
- `bin/docs-openapi` derives extension tags and slugs from `RequireEnabledExtension:<slug>` in the route middleware, without a database. The CLI keeps one slug list only to show `UNKNOWN` when the Gateway cannot answer.
- While `documents` is off, its background cleanup still runs and still needs its storage credentials.
- While `vault` is off, a published item stays as it was. A credential that changed or a Database user that was removed meanwhile is fixed on the first sync after the switch turns on.
- While `conn` is off, a T3 server cannot register and a device cannot read its profile. The records stay.

## Affects

- Components: apps/gateway, apps/cli, apps/web, apps/docs
- ADRs: none
- Detail: [Extensions](/reference/extensions)
- Verify: `tests/Unit/Architecture/ExtensionBoundaryTest.php`, `tests/Feature/Api/ExtensionsTest.php`, `tests/Feature/Domain/Activity/ActivityInputRedactionTest.php`, `tests/Feature/Http/Middleware/ValidateRequestBodySizeTest.php`, `tests/Feature/Infrastructure/Gateway/GatewayServiceStartHookTest.php`, the tests under `tests/Feature/Extensions/{Documents,Vault,Conn}`, and `composer test:affected` in apps/gateway and apps/cli
