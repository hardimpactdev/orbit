# Solo 754 blast-radius inventory

Candidate: `3761c3ec015a642c6d0efa885bfe847eb6ea51a6`

This inventory covers the reviewer’s full ProxyRoute scope: Gateway
application readers, all ProxyRoute migrations, direct CLI projections, SDK
request/response consumers, and the OpenAPI generator. It was rerun after the
reviewer 2466 fixes.

## Search receipt and scope accounting

- `rg -l 'ProxyRoute|proxy_routes|proxy-routes' apps/gateway/app
  apps/gateway/database/migrations apps/cli/app packages/sdk/src`: 101 direct
  token matches: 76 files below `apps/gateway/app` (75 Gateway application
  files plus the OpenAPI generator), 5 migrations, 15 CLI files, and 5 SDK
  files.
- Five semantic neighbors do not match that token search but are in scope:
  `2026_06_17_010000_normalize_metrics_route_upstream.php`,
  `AppRemoveCommand.php`, `AppRootCommand.php`, `ToolCatalog.php`, and
  `NodeRoleAssignmentService.php`.
- All migrations that create, alter, or delegate a backfill of `proxy_routes`:
  6 files.
- Direct and adjacent CLI command/projection boundaries: 17 files.
- SDK request/response consumers: 5 source files.
- OpenAPI projection generator: 1 file.
- Candidate total: 106 independently classified files. This reconciles the
  reviewer’s expanded scope: 101 direct search matches plus five semantic
  neighbors.
- Clean-candidate recount at `3761c3ec015a642c6d0efa885bfe847eb6ea51a6`
  reproduced the same 101-file direct split and the same five semantic
  neighbors. The classified blast inventory therefore contains exactly 106
  files for this candidate.

The adjacent `ApplicationLogGatewayClient` facade was also read. It delegates
unchanged arrays and owns no mutation or projection rule. Four Process commands
that mention `/api/proxy-routes` only as process-host lookup input were read and
remain downstream of the classified CLI matcher. The two SDK Mago baseline
files are tooling data, not runtime consumers.

## Gateway application: 76 files

Every file below was read and classified. A file can appear in a read category
and a mutation category because lifecycle services read before they write.

- App and Workspace lifecycle reads (7): `EnactAppRuntime`,
  `EnsureAppProxyRoute`, `RemoveApp`, `CreateWorkspacePlan`, `RemoveWorkspace`,
  `SetupWorkspace`, and `SetupWorkspacePlan`. Mutation authority is confined to
  the two Ensure actions and the two Remove actions; complete Instance or
  Workspace ownership is required before convergence or deletion.
- HTTP input and public projection (6): `AppStoreController`,
  `ResolvesVisibleToolNodes`, `ProxyRouteDestroyController`,
  `ProxyRouteListController`, `ProxyRouteStoreController`, and
  `ProcessBrowserCors`. The list controller projects validated gateway intent;
  add/remove controllers mutate only custom or proven orphan intent.
- Models and relations (3): `Instance`, `ProxyRoute`, and `Workspace`. These own
  schema casts and relationships, not standalone mutation policy.
- Analytics boundaries (5): `AnalyticsProxyDoctorProbe`,
  `AnalyticsPublicProxyDoctorProbe`, `AnalyticsRouteRegistrar`,
  `AppAnalyticsBindingService`, and `AppAnalyticsPayloadFactory`. The registrar
  is the route writer/deleter; the other files probe or project registrar-owned
  intent.
- App registration/backfill (2): `AppProxyRouteRuntimeUpstreamBackfill` and
  `AppRegistrar`. The backfill mutates only complete direct Instance ownership;
  the registrar delegates route convergence.
- DNS projection (2): `ProxyDnsmasqRecordsBuilder` and `S3BackendDnsRecords`.
  Both are read-only projections of validated route intent.
- Generic Doctor orchestration (9): `DoctorAdoptPolicy`, `DoctorAdoptRunner`,
  `DoctorManagedProxyRestorer`, `DoctorProxyFamilyProbe`,
  `DoctorProxyRestorer`, `DoctorProxyRouteInventory`,
  `DoctorProxyRouteRestorer`, `DoctorReportRunner`, and `DoctorRestoreSupport`.
  They delegate ownership decisions to the classified proxy validators,
  renderer, probe, and fixer.
- Metrics/role lifecycle (4): `MetricsRouteUpstreamBackfill`,
  `MetricsServiceRoute`, `NodeRoleDependencyInspector`, and
  `MetricsRoleBaseline`. The backfill now requires the exact legacy/current
  metrics config, null foreign keys, proxy kind, and canonical active router.
  The baseline owns normal metrics convergence and dependency cleanup.
  `NodeRoleAssignmentService` is the adjacent semantic lifecycle coordinator:
  forced ingress removal snapshots valid dependent public routes before the
  assignment enters `removing`, then delegates their deletion to the
  inspector. It has no direct ProxyRoute token.
- Runtime projections (2): `AgentPushPhpRuntimeArtifactConverger` and
  `ProcessAppHostnameResolver`. Both read route identity; neither creates a new
  ownership rule.
- Proxy ownership, projection, enactment, and repair (18):
  `AgentToolProxyRouteIntent`, `AppProxyRouteCaddyInstaller`,
  `AppProxyRouteRuntimeTargets`, `AppProxyRouteTargetResolver`,
  `InstanceProxyRouteOwnershipResolver`, `NonInstanceProxyRouteOwnership`,
  `ProxyRouteAdopter`, `ProxyRouteEnactment`, `ProxyRouteFileProbeContract`,
  `ProxyRouteFixer`, `ProxyRouteIntent`, `ProxyRouteOwnershipCompatibility`,
  `ProxyRouteProbe`, `ProxyRouteQuery`, `ProxyRouteRenderer`,
  `PublicBindingProxyRouteOwnership`, `WorkspaceProxyRouteOwnership`, and
  `WorkspaceProxyRouteOwnershipResolver`. `NonInstanceProxyRouteOwnership` is
  now the one complete custom/tool/router/S3/gateway validator used before
  generic render, probe classification, and repair. Direct/public Instance and
  Workspace families retain their existing complete resolvers.
- S3 boundaries (5): `S3CredentialsAction`, `S3ProxyDoctorProbe`,
  `S3PublishAction`, `S3RouteRegistrar`, and `S3UnpublishAction`. The registrar
  owns service/public route writes and deletion. Publish/unpublish mutate the
  SeaweedFS public-host list only after complete canonical route preflight.
- Tool lifecycle/projection (9): `AgentToolConsumerUrlProbe`,
  `LegacyOpenClawRuntimeCleanup`, `LegacyOpenCodeRuntimeCleanup`,
  `LegacyPolyscopeRuntimeCleanup`, `StaleToolIntentRemover`, `ToolInstaller`,
  `ToolCatalog`, `ToolPayloadMapper`, and `ToolRemover`. `ToolCatalog` is the
  semantic source for supported tool identity, node compatibility, and
  route-TLD requirements even though it has no direct `ProxyRoute` token.
  Agent-tool route writes/deletes flow through `AgentToolProxyRouteIntent` or
  `StaleToolIntentRemover`; adjacent NodeTool deletion does not create
  ProxyRoute authority.
- WebSocket boundaries (2): `WebSocketProxyDoctorProbe` and
  `WebSocketRouteRegistrar`. The registrar owns route writes/deletes; Doctor is
  read-only except through the classified generic repair path.
- Workspace convergence/projection (2): `EnsureWorkspaceProxyRoute` and
  `WorkspaceUrlResolver`. The Ensure action writes only complete Workspace plus
  Instance ownership; the resolver is read-only.

## Gateway mutation inventory

- Direct creators/convergers: `EnsureAppProxyRoute`,
  `EnsureWorkspaceProxyRoute`, `AnalyticsRouteRegistrar`,
  `WebSocketRouteRegistrar`, `S3RouteRegistrar`, `MetricsRoleBaseline`,
  `AgentToolProxyRouteIntent`, `ProxyRouteAdopter`, and `ProxyRouteIntent`.
- Save-based backfills/repairs: `AppProxyRouteRuntimeUpstreamBackfill`,
  `MetricsRouteUpstreamBackfill`, `ProxyRouteFixer`, and post-enactment saves in
  the Ensure/Intent services.
- ProxyRoute deleters: `EnsureAppProxyRoute`, `RemoveApp`, `RemoveWorkspace`,
  `AnalyticsRouteRegistrar`, `NodeRoleDependencyInspector`,
  `MetricsRoleBaseline`, `ProxyRouteIntent`, `S3RouteRegistrar`,
  `StaleToolIntentRemover`, and `WebSocketRouteRegistrar`.
  `NodeRoleAssignmentService` is the semantic caller that preserves the valid
  ingress-dependent route IDs across the forced-removal state transition.

All mutations above fail closed on malformed ownership. Generic repair now uses
the shared complete non-Instance validator. Wrong node/role, non-canonical
router or ingress, stray App/Workspace/Instance FKs, wrong kind, or unstable
family config cannot authorize repair.

## Migrations: 6 files

- `2026_05_05_203524_create_proxy_routes_table.php`: schema creation only.
- `2026_05_05_211400_add_workspace_id_to_proxy_routes_table.php`: Workspace FK
  schema transition only.
- `2026_05_21_130000_add_docker_first_runtime_fields.php`: App runtime backfill;
  complete direct ownership guards precede mutation.
- `2026_06_10_000002_normalize_proxy_route_service_owner_types.php`: owner-type
  mutation only for the canonical active router (`websocket.orbit` and
  `s3.orbit`) or canonical active ingress (public S3), with exact legacy
  owner/kind/null-FK/family identity. Up and down leave wrong-node rows intact.
- `2026_06_17_010000_normalize_metrics_route_upstream.php`: delegates its DML
  to the classified `MetricsRouteUpstreamBackfill` service.
- `2026_08_16_231522_persist_proxy_route_instance_ownership.php`: validates all
  positive assignments before DML; non-Instance rows with foreign Instance
  identity fail closed; rollback preserves validated Instance identity.

The delegated metrics service now validates the exact stable family plus
canonical router before config or source-hash mutation.

## CLI mutation and projection boundaries: 17 files

- `InstanceLogCommand`, `WorkspaceLogCommand`, and
  `ResolvesApplicationLogProxyTargets`: read proxy inventory and select an
  application-log endpoint. They do not mutate route state.
- `ApplicationLogInstanceTargetResolver`, `ApplicationLogProxyRouteMatcher`,
  `ApplicationLogProxyRouteOwner`, and `ApplicationLogProxyWorkspaceOwner`:
  exact-host projection chain. Instance selection requires canonical
  `owner.type=instance`; the safe compatibility form is allowed only when the
  complete owner object is absent and `target.type=instance`. Router, S3, tool,
  gateway, custom, partial-owner, and other dotted names cannot become Instance
  selectors. Workspace stays a Workspace target and requires its parent
  Instance projection.
- `ProxyAddCommand`: mutation client for custom route payloads only.
- `ProxyRemoveCommand`: mutation client for custom or gateway-proven orphan
  removal only.
- `ProxyListCommand`: read-only renderer of gateway owner/kind/target fields; it
  does not infer ownership from dotted strings.
- `AppRemoveCommand`: adjacent lifecycle client. It reports gateway-owned
  removal of app proxy routes and does not infer, rewrite, or delete route
  ownership locally.
- `AppRootCommand`: adjacent instance mutation client. Its progress text names
  proxy application, but it delegates the canonical instance selector and all
  route writes to the Gateway.

`ApplicationLogGatewayClient` is a direct-token delegation facade. The four
Process commands (`ProcessListCommand`, `ProcessStartCommand`,
`ProcessStopCommand`, `ProcessRestartCommand`) use the same exact-host matcher
only to resolve process context and introduce no separate route projection.

## SDK and OpenAPI consumers: 6 files

- `AddProxyRouteRequest` and `RemoveProxyRouteRequest`: custom mutation request
  transport; neither derives owner identity.
- `ListProxyRoutesRequest`: read transport for the gateway projection.
- `ProxyRouteListResponse` and `ProxyRouteMutationResponse`: envelope DTOs that
  preserve gateway data without dotted-name inference or alternate ownership.
- `GatewayOpenApi`: documents strict registered-host process/application-log
  selection and proxy mutation/list surfaces. No schema permits a non-Instance
  owner name to become an Instance selector.

## Family result

- App primary, Workspace, analytics, and WebSocket public routes retain their
  complete Instance-backed validators.
- Custom and tool routes require their intended active serving role, null
  foreign keys, kind, and stable config/identity.
- Analytics, WebSocket, S3, and metrics router services require the canonical
  active router and stable family config.
- Public S3 requires the canonical active ingress, canonical router handoff,
  null foreign keys, and exact stable publication config.
- Gateway requires the canonical active gateway, internal kind, null foreign
  keys, and a stable upstream target.
- Migration and metrics backfill mutations use the same canonical-selection
  rule as current family intent and leave malformed/wrong-node rows unchanged.

Result: complete. All 106 candidate files in the reviewer-expanded scope were
classified. The adjacent facades and tooling-only matches were also checked.
No unclassified mutation or projection boundary remains.
