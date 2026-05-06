# 5_app — App Workstream

Detail file for the app command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/5_app/`.

Unblocked for read-only slices. App write/destructive commands stay blocked
until app read commands and the required node write-forwarding/provisioning
safety gates are clear.

## Workstream

- [x] Convert app command docs into current format.
- [x] Create app abstraction reference (`docs/abstractions/5_app.md`).
- [x] Port app schema and models needed by documented app commands.
- [x] Port gateway API list support (`GET /api/apps` + `ListAppsRequest`).
- [x] Port `app:list`.
- [x] Port gateway API show support (`GET /api/apps/{name}` + `ShowAppRequest`).
- [x] Port `app:show`.
- [x] Port minimal `RemoteShell` foundation needed by gateway-owned app writes.
- [x] Port `app:new`.
  - [x] Gateway-local JSON/non-interactive slice: validates static input,
    creates source on the target app node through `RemoteShell`, writes gateway
    app intent only after source creation succeeds, returns the documented JSON
    success/error envelope, and preserves failure-before-write behavior for
    source creation errors.
  - [x] Gateway API endpoint and configured control-caller forwarding:
    `POST /api/apps`, typed `CreateAppRequest` / `AppCreateResponse`,
    access-policy authorization for target app nodes, preserved structured
    errors, and no local app row or direct app-node SSH from control callers.
  - [x] Interactive input mode and progress-tree human renderer: missing app
    name and target app node prompt in interactive human mode, optional
    repository prompt canonicalizes GitHub shorthand, validation failures render
    before the progress tree, and successful human output includes the
    documented progress tree and completion summary.
  - [x] Registration pipeline artifact convergence (PHP-FPM, proxy route,
    process artifacts) and related warning handoffs.
    - [x] Runtime warning handoff foundation: after durable app intent is
      written, `app:new` probes PHP-FPM availability on the owning app node and
      reports retryable `app.php_version_unavailable` warnings without rolling
      back registry intent.
    - [x] PHP-FPM pool rendering/install/reload: writes a managed per-app pool
      config on the owning app node and reloads the matching PHP-FPM service
      when the runtime is available.
    - [x] Proxy route registry/enactment handoff: `app:new` now records
      app-owned `proxy_routes` intent, enacts the Caddy site on the owning app
      node, preserves intent with `proxy.enactment_failed` warnings when backend
      enactment needs later convergence, and rejects route-domain conflicts
      before source creation.
    - [x] Process runtime-unit rendering/enactment foundation: process intent
      schema/models exist, app registration can render existing app-owned
      process definitions as Supervisor programs on the owning app node, and
      missing Supervisor/runtime-unit enactment is surfaced as process-family
      warnings. No undocumented default process definitions are created by
      `app:new`.
      - [x] E2E gates for real source creation and registration convergence.
        - [x] Docker feature E2E for real source creation from a control caller
          through the gateway API and gateway-owned `RemoteShell` SSH edge. The
          Docker feature lane intentionally lacks PHP-FPM/Caddy runtime realism, so
          it asserts source creation, durable app intent, and the retryable
          `app.php_version_unavailable` warning.
        - [x] Provisioning-lane E2E for real PHP-FPM, proxy route, and process
          artifact convergence after source creation.
- [x] Port `app:register`.
  - [x] Gateway-local JSON/non-interactive adoption and convergence slice:
    validates static input, rejects app-role callers before side effects,
    verifies the target path over gateway-owned `RemoteShell`, writes or refreshes
    gateway app intent, preserves repository metadata, surfaces path collisions,
    and reuses the app runtime enactment pipeline for PHP-FPM/proxy/process
    warnings.
  - [x] Gateway API endpoint and configured control-caller forwarding:
    configured control callers now use a typed gateway request, the gateway API
    authorizes target app-node access, and gateway-local registration remains the
    only SSH edge to app nodes.
  - [x] Interactive input mode and human renderer progress tree.
  - [x] Docker feature E2E registration/adoption gate through the gateway API.
  - [x] Production activation retry warnings: production-domain registration
    persists intent and returns retryable `proxy.domain_inactive` warnings that
    point back to `app:register`.
  - [x] Provisioning-lane registration refresh gate.
- [x] Port `app:root`.
  - [x] Gateway-local JSON/non-interactive intent update slice: resolves app
    names/hosts from gateway state, validates document roots lexically against
    the app path, updates `document_root`, and reuses app runtime enactment for
    PHP-FPM/proxy refresh.
  - [x] Gateway API endpoint and configured control-caller forwarding.
  - [x] Interactive input mode and human renderer progress tree.
  - [x] E2E root refresh gate.
- [x] Port `app:remove`.
  - [x] Gateway-local JSON/non-interactive intent removal slice: requires
    destructive consent, resolves apps from gateway state, deletes app intent,
    removes currently implemented app-owned proxy/process intent, attempts
    node artifact cleanup through the gateway-owned RemoteShell edge, and
    reports retryable cleanup drift as success warnings after intent removal.
  - [x] Gateway API endpoint and configured control-caller forwarding.
  - [x] Interactive input mode and human renderer progress tree.
  - [x] E2E removal cleanup gate.
- [!] Port `app:prune`.
  - Blocked until workspace schema/models and workspace removal semantics exist.
    `app:prune` discovers stale workspace intent and delegates removal through
    `workspace:remove`; without gateway-tracked workspaces it cannot satisfy the
    current command contract. Next concrete action: port workspace registry
    reads and removal semantics, then return to `app:prune`.
- [~] Port `app:agent-ide`.
  - [x] Gateway-local JSON/non-interactive app adapter intent slice: stores
    app-level adapter overrides, supports `inherit` and `none`, resolves
    effective adapter through the owning node default, and returns empty
    workspace cleanup until workspace intent exists.
  - [x] Gateway API endpoint and configured control-caller forwarding.
  - [x] Interactive input mode and human renderer.
  - [ ] Workspace cleanup planning once workspace schema/removal exists.
  - [x] E2E adapter intent gate.
- [ ] Decide whether legacy app helper commands such as `app:link`,
  `app:secure`, `app:status`, `app:sync`, and scheduler commands should get
  converted docs or stay retired.

## App family doctor

- [~] Port app doctor contracts and checks.
  - [x] Registry-only `AppsProbe` foundation: app record completeness,
    owning-node eligibility, and app agent IDE default checks.
  - [x] Source path and document root reality checks.
  - [x] PHP runtime availability checks.
  - [x] PHP-FPM configuration presence and content checks.
  - [!] External app runtime artifact checks: runtime configuration, production
    policy, deployment health, and stale app artifacts.
    - Blocked by missing clean-rebuild schema/intent for app runtime
      configuration, production user policy, deployment steps, deployment runs,
      latest deployment status, and stale app artifact discovery scope.
    - Next concrete action: port the deploy-policy/run-history schema and app
      production/runtime intent fields, or explicitly narrow `app-doctor` to
      the currently implemented app runtime artifacts before adding more probe
      checks.

## App workstream entry point

The app family ports use the Saloon `GatewayConnector` / `GatewayRequest` /
typed DTO pattern, declare a `## Activity Logging` section in each command's
`technical/1_<command>.md` per
[`activity-concepts.md`](../commands/17_activity/activity-concepts.md), and add
the command to `ActivityLoggingContractRule::ENFORCED_COMMANDS` as the
section lands.

The first app slices stayed read-only and used Docker-backed feature E2E
before any app write/destructive commands were created:

1. `APP-ABSTRACTION-1` — created `docs/abstractions/5_app.md` from app command
   docs, old app evidence, and cross-cutting patterns. **Implemented.**
2. `APP-SCHEMA-1` — ported app schema and Eloquent model for the apps table.
   **Implemented.**
3. `APP-API-LIST-1` — gateway-side `GET /api/apps` + `ListAppsRequest`.
   **Implemented.**
4. `APP-LIST-1` — `app:list` command (paired in-memory Pest + ephemeral Pest E2E).
   **Implemented.** Docker feature E2E passed with
   `composer test:e2e -- --filter='App(List|Show)'`.
5. `APP-API-SHOW-1` — gateway-side `GET /api/apps/{name}` + `ShowAppRequest`.
   **Implemented.**
6. `APP-SHOW-1` — `app:show` command (paired in-memory Pest + ephemeral Pest E2E).
   **Implemented.**
7. `APP-REMOTE-SHELL-1` — minimal gateway-owned `RemoteShell` foundation for
   app write enactment. **Implemented.**
