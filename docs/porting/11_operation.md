# 11_operation — Operation Workstream

Detail file for the operation command family. Top-level command status lives
in [`PORTING.md`](PORTING.md). Doc authority: `docs/commands/11_operation/`.

## update

- [x] `update`
  - Current implementation: `app/Console/Commands/UpdateCommand.php`
  - Current docs: `docs/commands/11_operation/1_update`
  - Current tests:
    - `tests/Feature/Commands/UpdateCommandTest.php`
    - `tests/Feature/Commands/Operations/UpdateCommandTest.php`
    - `tests/Feature/Commands/Operations/UpdateJsonRendererTest.php`
    - `tests/Feature/Commands/Operations/UpdateHumanRendererTest.php`
  - Contract gaps: resolved.
    - JSON renderer implementation.
    - Tree-style human progress output.
    - Split operation contract tests mapped by the current docs.

## update:all

- [~] `update:all`
  - Current implementation: `app/Console/Commands/UpdateAllCommand.php`
  - Current docs: `docs/commands/11_operation/2_update-all`
  - Current tests:
    - `tests/Feature/Commands/UpdateAllCommandTest.php`
    - `tests/Feature/Commands/Operations/UpdateAllJsonRendererTest.php`
    - `tests/Feature/Commands/Operations/UpdateAllHumanRendererTest.php`
  - Contract gaps resolved:
    - [x] JSON renderer implementation.
    - [x] tree-style per-installation human progress output.
    - [x] control-node exclusion: control nodes are never remote update targets.
    - [x] split operation contract tests mapped by the current docs.
  - Contract gaps remaining:
    - caller-role and gateway authorization contract.
    - intent source split: control caller must read node intent from the
      Gateway API, not from any local node table. Gateway caller reads local
      gateway state.
    - execution topology: gateway-owned `RemoteShell` is the only legal SSH
      edge. Control caller must not SSH to other nodes.
  - Historical topology note: gateway-to-beast updates work. The earlier
    gateway-to-mini `Permission denied (publickey)` symptom reflected an
    implementation that targeted the mini control node; under the clarified
    contract, mini is excluded from remote targets entirely.

## profile

- [~] `profile`
  - Current implementation:
    - `app/Console/Commands/ProfileCommand.php`
    - `app/Actions/Profile/ShowProfile.php`
    - `app/Services/CurlRequestProfiler.php`
  - Current docs: `docs/commands/11_operation/4_profile`
  - Current tests:
    - `tests/Feature/Commands/Operations/ProfileCommandTest.php` (gateway-state baseline JSON, validation, non-2xx success)
    - `tests/Feature/Commands/Operations/ProfileHumanRendererTest.php` (baseline human renderer)
    - `tests/Unit/Services/CurlRequestProfilerTest.php` (baseline HTTP timing extraction)
    - `tests/E2E/ProfileTest.php` (Docker feature E2E for observable control-caller profile target)
  - Bootstrap slice implemented: gateway caller app/domain/path/full-URL target
    resolution against gateway state, `--node` scoping validation, baseline cURL timing capture,
    request id and Toolbar auth headers, baseline JSON envelope, baseline human
    output, and completed non-2xx success semantics.
  - Gateway resolution slice implemented: control callers resolve named/domain
    targets through typed `ShowAppRequest`, preserve baseline profiling from
    the caller process, and report `origin=caller`.
  - Gateway-origin API slice implemented: `GET /api/profile` resolves and
    authorizes visible apps on the gateway, performs the profile request from
    gateway origin, and app callers use typed `ShowProfileRequest` instead of a
    caller-local HTTP profile edge.
  - Request-origin fallback slice implemented: control callers first attempt
    caller-origin profiling for resolved targets, then fall back to typed
    gateway-origin profiling when the caller-local request cannot complete.
  - Gateway-caller cwd inference slice implemented: omitted targets on gateway
    callers resolve from the current working directory when it maps to an app
    path known by the gateway registry.
  - App-caller cwd inference slice implemented: omitted targets on app callers
    use the current working directory as a gateway-authorized path selector, and
    `GET /api/profile` resolves visible app records by absolute app paths.
  - Interactive selector slice implemented: when no explicit target or cwd app
    context resolves, interactive callers can choose from visible app targets
    through the documented `profile.app` datatable prompt.
  - Toolbar human renderer slice implemented: decoded Toolbar stages, collection
    overhead, and query summary counts render in human output when available.
  - Paired Docker feature E2E gate implemented: control callers resolve a
    registered app through the gateway and profile an observable HTTPS route.
  - Contract gaps:
    - workspace cwd inference is blocked until workspace schema/models exist.

## doctor

- [~] `doctor` — verify-mode dispatcher + family-specific probes ported.
  `DoctorReportRunner::SUPPORTED_FAMILIES` currently dispatches `node`,
  `proxy`, `firewall_rule`, `tool`, and `schedule`. Generic `--fix` / `--adopt`
  orchestration reaches family dispatch and records unsupported actions as
  skipped without running side effects.
  - [x] Verify-mode + dispatch for: `node`, `proxy`, `firewall_rule`, `tool`,
    `schedule`.
  - [ ] Verify-mode dispatch for `app`, `workspace`, and `process` families.
    `AppsProbe`, `WorkspacesProbe`, and `ProcessesProbe` exist but are not yet
    wired into `DoctorReportRunner` / `DoctorScopeValidator`.
  - [!] Streaming progress and family-owned `--fix` / `--adopt` action handlers
    remain blocked per family. See
    [`state-families-doctor.md`](state-families-doctor.md) for per-family
    detail.
