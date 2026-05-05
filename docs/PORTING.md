# Orbit Porting Tracker

This file tracks the clean rebuild work still needed to recreate useful Orbit
behavior from `../orbit-old-may`.

Implementation-pattern guidance for command porting lives in
`docs/abstractions/`. Before porting a command, workers read
`docs/abstractions/cross-cutting.md` plus the matching family file.

## Rules

- Current `docs/` are product authority.
- `docs/abstractions/` is implementation guidance, not product authority. When
  abstraction guidance and command docs conflict, update the abstraction docs or
  ask for direction; do not override the command contract.
- `../orbit-old-may` is implementation evidence, not product authority.
- Old features should be treated as reference material, not a mandate to copy
  their structure. Before porting behavior, verify whether the clean rebuild can
  implement it more simply, safely, or directly against the current contracts.
- Before the first implementation todo for a family is promoted to
  `worker-ready`, `docs/abstractions/<n>_<family>.md` must exist.
- Command-port implementer todos must read
  `docs/abstractions/cross-cutting.md` and the relevant family abstraction file
  before code edits. If the family abstraction file is missing, the worker marks
  the todo `needs-direction` instead of inventing patterns.
- When all read commands in a family are ported, or when a deliberate subset
  proves the implementation shape, add a concrete family-review candidate under
  `Todo Pipeline Hints`. The pipeline filler turns that entry into a normal
  worker todo tagged `family-review`.
- The next family's abstraction seed may be authored while the previous family
  review is open. The next family's implementation todos must not be promoted
  to `worker-ready` until the previous `family-review` todo is merged or
  explicitly deferred here with a reason.
- If we decide to keep a feature or command that exists only in
  `../orbit-old-may/docs`, port its documentation into this repo before
  implementing it.
- Legacy command docs must be converted into the current command-doc format
  before the command is built here.
- Every migrated implementation slice must cite the current docs it implements
  and the old code it used as evidence.
- Standing live infrastructure is not a test lane. Do not use persistent
  gateway, control, or app nodes as verification targets.
- In-memory Pest tests own deterministic command, service, database, renderer,
  and contract coverage.
- Provisioning, destructive, host-mutation, live transport, and repair/adoption
  flows require the `e2e-provision` lane before they can be treated as fully
  verified.
- Every newly-ported command requires focused in-memory Pest coverage and one
  E2E-* gate todo before its workstream entry can flip to `[x]`. The E2E gate
  may declare `lane=none` only when the command is docs-only, a pure refactor,
  or has no observable runtime behavior outside Pest.

## Status Legend

- `[ ]` Not started
- `[~]` In progress
- `[x]` Ported and tested
- `[!]` Blocked or decision needed
- `[-]` Intentionally not ported

Use `[x]` only when the current implementation satisfies the current product
docs for that item and has focused tests for the documented contract. Useful
bootstrap slices that do not yet satisfy the full current contract stay `[~]`.

## Porting Workflow

1. Find the old documentation in `../orbit-old-may/docs`.
2. Port or convert that documentation into this repo first.
3. For command docs, use the converted family directory/split-file shape and
   run the command-designer semantic check for each ported command before
   marking the command or family done.
4. Run `composer docs-lint` when command docs changed.
5. Before implementation, read `docs/abstractions/cross-cutting.md` and the
   relevant `docs/abstractions/<n>_<family>.md` file.
6. Inspect the old implementation in `../orbit-old-may/app`,
   `../orbit-old-may/config`, `../orbit-old-may/database`, and
   `../orbit-old-may/tests`.
7. Decide whether the old implementation should be ported directly or replaced
   with a simpler clean-rebuild approach that better fits the current docs.
8. Respect the implementation order below unless a verification-helper command
   unlocks better testing for the next slice.
9. Implement the smallest useful vertical slice in the clean repo.
10. Add focused Pest tests that assert the current docs contract, not legacy
   internals.
11. Run the narrow test, then `composer quality-check`.
12. Update this tracker in the same commit as the ported slice.

## Implementation Order

Default migration order is command-contract and capability driven:

1. **Foundation and verification harness.**
   - Keep `composer quality-check` green for in-memory Pest coverage.
   - Expand the Incus-backed ephemeral E2E harness before provisioning or
     destructive flows depend on it.
    - Use blank Incus VM snapshots for the `e2e-provision` lane and ready
      Incus VM snapshots for the `e2e-feature` lane.
   - Convert docs for the next implementation slice before writing code.
   - Create or refresh the matching `docs/abstractions/<n>_<family>.md` before
     the first implementation todo for a family is promoted to `worker-ready`.
2. **Nodes first.**
   - Finish node registry read and metadata commands before app/workspace
     commands depend on them.
   - Complete access-policy and identity foundations: caller role, local node
     identity, grants, gateway API reachability, and `/api/me`.
   - Port node provisioning only after ephemeral E2E is ready enough to verify
     host mutation safely.
3. **Verification-helper commands may move earlier when they improve testing.**
   - `profile` is an early candidate once node identity and minimal app
     resolution exist, because it helps validate app routing, TLS, and runtime
     behavior while later app/workspace work is being ported.
   - `doctor` family docs and read-only checks may also move earlier when they
     expose drift needed to verify node or app slices.
   - These commands still follow docs-first conversion and must not jump ahead
     of their prerequisites.
4. **Apps after nodes.**
   - Port app schema, API transport, read commands, and app creation/removal
     once node selection and access semantics are reliable.
5. **Workspaces after apps.**
   - Port workspace commands after app ownership, paths, URLs, and runtime
     routing are available.
6. **Processes after workspaces.**
   - Port process commands after the app/workspace execution context is stable.
7. **State families and doctor integration.**
   - Port each family when its owning command domain needs intent/reality
     convergence.
8. **Tools, schedules, proxy/firewall, deployments, Cloudflare, VPN, PHP, and
   agent IDE commands.**
   - Port these after their required node/app/workspace/process foundations
     exist, unless one command is needed earlier as a verification helper.

## Current Clean Implementation

These items exist in the clean repo today. Some are bootstrap slices and do not
yet satisfy the full product contracts.

- [x] Incus-backed ephemeral E2E harness (retired legacy shell harness; now
    Artisan commands + Pest E2E suite)
  - Current commands: `php artisan e2e:*`
  - Current docs: `TESTING.md`
  - Current test script: `composer test:e2e`
  - Bootstrap slice implemented: beast preflight, disposable Ubuntu cloud VM
    launch, ephemeral SSH key injection, SSH readiness check from beast, and VM
    cleanup.
  - Base image lane implemented: `composer e2e:prepare-base-image -- --force`
    builds the reusable `orbit-base-ubuntu-26.04` Incus image with stable
    system dependencies.
  - Provisioning lane implemented: `composer test:e2e:provision` runs
    installer and host-mutation checks on disposable VMs only. Keep it
    out of the default feature lane.
  - First-gateway provisioning lane implemented: `composer test:e2e:provision -- --filter='NodeNewGateway'`
    launches disposable VMs, runs `orbit node:new --role=gateway`, and verifies
    the gateway is provisioned under the steady-state `orbit` user with a
    working Orbit installation.
  - First-gateway WireGuard enrollment lane implemented:
    `composer test:e2e:provision -- --filter='NodeNewWireGuard'` verifies real
    gateway/control WireGuard interfaces, gateway peer persistence, gateway API
    reachability through the WireGuard address, and idempotent first-gateway
    convergence on disposable Incus VMs.
  - Prepared topology lanes implemented: `composer e2e:prepare-topology -- --force control-gateway-dev-prod`
    builds reusable Incus templates for control, gateway, development app, and
    production app roles; `composer test:e2e:topology-contract` verifies the
    prepared full topology contract before feature tests rely on it.
  - Docker feature topology lane implemented for container-safe E2E:
    `composer e2e:prepare-docker-runtime -- --force`,
    `composer e2e:prepare-docker-topology -- --force control-gateway-dev-prod`,
    and `composer test:e2e`. The recommended local topology is sidecar1 and
    sidecar2 as the primary Docker feature pool, with Beast as Docker overflow
    only when it is not holding an Incus provisioning lease. Incus provisioning
    runs on Beast only (`ORBIT_E2E_INCUS_HOSTS=beast`,
    `ORBIT_E2E_INCUS_HOST_SLOTS=beast:1`,
    `ORBIT_E2E_EXCLUSIVE_HOSTS=beast`). Docker is the default feature provider;
    Incus remains the VM-realism lane for provisioning, WireGuard, systemd, SSH,
    package installation, trust-store, and VPS-adjacent behavior.
- [~] Orbit host installer
  - Current implementation: `bin/install-orbit`
  - Current tests: `tests/Feature/Commands/NodeNewCommandTest.php`
  - Bootstrap slice implemented: local control/gateway/app host prerequisite
    installer for Ubuntu and macOS that installs PHP, Composer, Git, Orbit
    source, SQLite database state, migrations, and the `orbit` symlink. Ubuntu
    installs PHP 8.5 by default when the native package is available, with the
    same Ondrej PHP PPA pattern used by old Orbit as a fallback. Ubuntu gateway
    installs include WireGuard, Caddy, and PHP-FPM for first-gateway runtime
    verification. Ephemeral E2E
    uses Ubuntu 26.04 because its native PHP 8.5 packages avoid depending on
    Launchpad reachability from Incus guests.
  - UX note: follows the command-designer human output shape with immediate
    step-tree progress, stable error codes, quiet default logs, and `--verbose`
    for underlying package and shell command output.
  - Porting note: this is the first user touch point before any Orbit command
    can run on a fresh control node. It does not create gateway-owned node
    identity or WireGuard material. The ready control E2E lane intentionally
    installs as a non-`orbit` user because real control machines are expected to
    be user-owned, while gateway and app provisioning must create or prepare the
    node-side `orbit` user when needed.
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
- [~] `node:list`
  - Current implementation: `app/Console/Commands/NodeListCommand.php`
  - Current docs: `docs/commands/1_node/3_node-list`
  - Current tests:
    - `tests/Feature/Commands/NodeListCommandTest.php` (base contract, filters, validation, read-only)
    - `tests/Feature/Commands/Nodes/NodeListRolePathTest.php` (gateway-local vs control/app forwarding paths)
    - `tests/Feature/Commands/Nodes/NodeListJsonRendererTest.php` (JSON envelope and field contract)
    - `tests/Feature/Commands/Nodes/NodeListHumanRendererTest.php` (human table and prose contract)
  - Contract gaps: access-policy visibility, `--doctor`, and E2E verification
    remain tracked in the node workstream.
- [~] `node:show`
  - Current implementation: `app/Console/Commands/NodeShowCommand.php`
  - Current docs: `docs/commands/1_node/4_node-show`
  - Current tests:
    - `tests/Feature/Commands/NodeShowCommandTest.php` (base contract, lookup, fallback, read-only)
    - `tests/Feature/Commands/Nodes/NodeShowCommandTest.php` (split command contract)
    - `tests/Feature/Commands/Nodes/NodeShowJsonRendererTest.php` (JSON envelope and field contract)
    - `tests/Feature/Commands/Nodes/NodeShowHumanRendererTest.php` (human output and prose contract)
    - `tests/Feature/Commands/Nodes/NodeShowInteractiveInputModeTest.php` (interactive input mode contract)
    - `tests/Feature/Commands/Nodes/NodeShowNonInteractiveInputModeTest.php` (non-interactive input mode contract)
    - `tests/Feature/Commands/Nodes/NodeShowRolePathTest.php` (gateway-local vs control/app forwarding paths)
  - Bootstrap slice implemented: active registry lookup, human output, JSON
    envelope, not-found error, local-node fallback, caller-role resolution, and
    read-only behavior.
  - Renderer contracts aligned with 6.1/6.2: `environment`, `platform`, and node
    agent IDE metadata are modeled; grants section always renders in human mode
    with `(none)` sentinel when empty.
  - Contract gaps tracked in the node workstream below.
- [~] `node:new`
  - Current implementation: `app/Console/Commands/NodeNewCommand.php`
  - Current docs: `docs/commands/1_node/1_node-new`
  - Current tests: `tests/Feature/Commands/NodeNewCommandTest.php`
  - Old evidence: `../orbit-old-may/app/Console/Commands/NodeNewCommand.php`
    and `../orbit-old-may/app/Services/RemoteProvisioner.php`.
  - Bootstrap slice implemented: unconfigured control caller can invoke
    first-gateway bootstrap with `--role=gateway`, ship the current Orbit
    source and `bin/install-orbit` over SSH, install PHP/Composer/Orbit on the
    gateway host, and persist local bootstrap registry rows for the gateway and
    initiating control node.
  - Contract gaps are tracked in the node workstream. First-gateway WireGuard
    enrollment, gateway CA trust, gateway API reachability, `/api/me`
    verification, first-gateway platform detection, and documented JSON success
    state are implemented. WireGuard, CA trust, and gateway API verification
    are covered by the passing `e2e-provision` lane.
- [-] `node:register` — **DECIDED: Retire as public command; keep as internal bootstrap utility.**
  - Current implementation: `app/Console/Commands/NodeRegisterCommand.php`
  - Current tests: `tests/Feature/Commands/NodeRegisterCommandTest.php`
  - Decision: This command is not a documented product command contract. There
    is no `node:register` page in `docs/commands/1_node/` and no legacy
    equivalent in `../orbit-old-may`. It exists only as a clean-rebuild
    bootstrap helper used internally by `node:new` to persist local registry
    rows during first-gateway bootstrap. It should not be reconciled as a
    public command because `node:new` already covers the public node
    registration contract, and `gateway:add` covers control-node onboarding.
  - Action: Do not port or convert `node:register` into a public command. Keep
    the current implementation as an internal utility until `node:new` no
    longer needs it, then fold it into a service class or retire it entirely.
- [x] Command docs linter
  - Current implementation: `tool/docs-linter`
  - Current script: `composer docs-lint`
  - Porting note: exits 0 with warnings for known command-doc complexity debt.

## Documentation Porting

Command or feature docs missing from this repo must be ported before rebuilding
the matching implementation. Converted command docs should follow the
directory/split-file format used by `docs/commands/1_node/1_node-new`.
When porting a legacy command domain, do not preserve flat legacy command
files. Each public command must live in its own numbered command directory with
at least a public command page, canonical technical contract, and output
renderer contracts. Add input-mode, caller-role, and other companion technical
files whenever the command has prompts, non-interactive differences,
destructive consent, topology behavior, or other split ownership.
After structural conversion, run the command-designer semantic check for each
ported command and family doctor file. Use
`.agents/skills/command-designer/references/semantic-check.md` and current
`docs/BLUEPRINT.md`, `docs/MISSION.md`, `docs/CONCEPTS.md`,
`docs/BUILDING-BLOCKS.md`, and `docs/commands/README.md` as authority. Fix
semantic issues before marking the command or family ported.

After structural porting, also run a legacy feature-detail audit before
considering a family complete. Search the old code and tests for
domain-specific capabilities that were encoded in implementation support rather
than legacy command prose, then document the product behavior in the new family
contracts. Examples include Vite/HMR network bind requirements, app/workspace
proxy ingress safeguards, tool credentials and service endpoints, and TLS trust
behavior. Do not document backend recipes or old implementation classes;
document the supported Orbit capability and ownership boundary.

### Todo Pipeline Hints

These hints are for the Solo pipeline filler. They describe todo sequencing
only; `docs/PORTING.md` workstream statuses remain the authority for completion.

#### Family Review Todos

Family-review candidates are normal worker todos tagged `family-review`. They
use the standard worker lifecycle and do not require a new Solo phase tag or
dispatcher path.

When the pipeline filler creates or refreshes a `family-review` todo, it uses
`docs/superpowers/plans/solo-orchestration/references/family-review-todo-template.md`
in addition to the base worker todo template.

#### Pairing Rule (Pest + E2E Per Port)

For every implementation todo the filler creates, also create the paired gate
todos required by the Rules section:

- `E2E-<short-id>` — ephemeral E2E gate todo. Tagged `e2e`, `e2e-gate`,
  `ephemeral`. Promote to `e2e-ready` (NOT `worker-ready`) on
  `SCOUT_REPORT status=READY`. Lane must declare a concrete
  `composer test:e2e:provision` invocation, `composer test:e2e`
  invocation, `php artisan e2e:*` command, or `lane=none` reason; if that lane
  does not yet exist, create a separate implementer todo to author it before the
  E2E gate becomes dispatchable.

E2E gate todos are dispatched only by the orchestrator's E2E role per
`references/todo-state.md`. Never promote them to `worker-ready` and never
route them to the implementer agent.

#### Feature E2E Checkout Rule

Command-port `e2e-feature` gates must test the branch or worktree that contains
the port. Prepared topology images and templates are reusable topology baselines;
they are not feature-code delivery vehicles. The E2E gate should acquire the
smallest prepared topology that covers the command, install or overlay the
current checkout into the disposable clone, and run `php artisan <command>` from
that checkout. Do not rebuild images, mutate templates, or repoint the clone's
steady-state `orbit` symlink just to expose a command under development.

If an E2E lane cannot test the current checkout this way, the gate is not ready.
Create an E2E harness todo first, or mark the gate blocked with the missing
checkout-overlay support.

#### Current E2E Setup For Porting Work

Use this setup before promoting new command-port E2E gates:

1. Build or refresh the stable Incus base image when apt/system dependencies
   change. Incus provisioning E2E runs on Beast only:

   ```bash
   ORBIT_E2E_INCUS_IMAGE_BUILD_HOST=beast \
   ORBIT_E2E_INCUS_HOSTS=beast \
   ORBIT_E2E_INCUS_HOST_SLOTS=beast:1 \
   ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e \
   ORBIT_E2E_EXCLUSIVE_HOSTS=beast \
   composer e2e:prepare-base-image -- --force
   ```

2. Rebuild the prepared superset topology from the current checkout whenever
   feature assertions need fresh baseline command code:

   ```bash
   ORBIT_E2E_HOST=beast \
   ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e \
   composer e2e:prepare-topology -- --force control-gateway-dev-prod
   ```

   The Beast `orbit-e2e` Incus storage pool is ZFS-backed and is the expected
   Incus pool for practical VM-backed feature E2E. Without
   `ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e`, clones fall back to the host's
   default storage and are much slower.

3. For feature ports, prefer the fast aggregate first:

   ```bash
   composer test:e2e
   ```

   This runs `e2e-feature` tests with Pest parallel mode, uses the Docker
   topology provider by default, acquires hosts through the shared `.env.e2e`
   lease pool, overlays the current checkout per test via the Pest helpers,
   and reuses the checkout cache for the process.

4. Add scenario-specific gates with Pest filters or groups only when the
   command truly needs a narrower topology contract:

   ```bash
   composer test:e2e -- --filter='DnsList'
   composer test:e2e -- --filter='NodeShowGrant'
   ```

5. Keep provisioning, installer, host mutation, and destructive setup flows out
   of the default feature lane. Pair those todos with
   `composer test:e2e:provision -- --filter=<test>` and leave failed VMs
   inspectable.

Docker remains the likely long-term default for fast feature regression
because container topology reset is cheaper and the runtime backend +
Orbit Scheduler run identically inside containers. Incus remains the
VM-realism lane for host init, real SSH, sudo, package installation, real
WireGuard, trust-store mutation, and VPS-adjacent behavior.

#### Sequencing Rules

- Do not start new implementation while an active final-review or push recovery
  todo is open.
- Count only open, unblocked, unlocked `worker-ready` todos as dispatchable
  worker capacity. Blocked `worker-ready` todos are planned inventory, not
  available queue.
- Count `e2e-ready` todos separately from `worker-ready`; both tags consume
  pipeline capacity but dispatch through different orchestrator paths.

#### Current Short Queue (After Saloon Node Family)

1. The read-forwarding chain is complete: `NODE-SHOW-CONTRACT-1` (todo 251),
   `NODE-READ-FWD-1` (todo 253), and `E2E-NODE-READ-1` (todo 254) are verified.
2. `FAMILY-REVIEW-NODE-READ-1` (todo 265) is complete. Findings:
   - Caller-role resolution evaluated against 11 concrete callers; extraction
     justified and tracked as follow-up candidate.
   - Typed gateway request, API envelope, renderer pairing, and role-path test
     shapes already promoted in `docs/abstractions/cross-cutting.md`.
   - JSON envelope response helper duplication identified; deferred to same
     follow-up todo as caller-role extraction.
3. `NODE-DOCTOR-CONTRACT-1` (todo 252) is merged: the technical contract,
   probe primitives, DTOs, and focused unit tests are on `main`.
4. `NODE-LIST-DOCTOR-1` (todo 270) is complete: `node:list --doctor`
   calls the merged `NodesProbe` primitives through a node doctor summary
   builder and renders human/JSON summaries on both gateway-local and
   forwarded gateway API paths.
5. `E2E-NODE-SHOW-GRANT-1` (todo 267) is implemented: the feature E2E
   overlays the current control and gateway checkouts, seeds real gateway
   `node_access` rows, asserts populated and empty `node:show` grant metadata,
   and checks human `(none)` grant rendering. Docker is the default feature
   lane; Incus feature-lane reruns are no longer an app-read blocker.
6. `E2E-PROVISION-REWORK-1` (todo 278) is implemented: provisioning E2E tests
   no longer launch role-specific ready control/gateway images. They stage a
   per-run bundle, launch from blank/base images, provision control/gateway VMs
   from the base image, and run in the `e2e-provision` lane. The full Incus
   provision-lane passed via `E2E-PROVISION-VERIFY-1` (todo 290).
7. `NODENEW-WIREGUARD-ENROLL-1` (todo 268) is implemented: first-gateway
   bootstrap now generates gateway/control WireGuard keys, sends the identity
   payload to the gateway-local bootstrap command over SSH stdin, persists
   gateway-side peer rows plus the local initiating-control peer, configures
   `wg-orbit` on the gateway, and covers fresh plus idempotent paths with
   focused Pest tests plus the passing `NodeNewWireGuard` provisioning E2E.
8. `SALOON-NODE-FAMILY-1` is complete: all existing node gateway client calls
   now use Saloon v4 request/DTO classes under `App\Http\Gateway`, including
   `node:list`, `node:show`, `node:grant`, `node:revoke`, `node:remove`,
   `node:update`, `node:default` app-node discovery, and `node:new` app-node
   creation forwarding.
9. The legacy `App\Services\Gateway\GatewayClient`,
   `GatewayRequestSender`, `GatewayResponse`, `GatewayResponseParser`, and
   old `App\Services\Gateway\Requests\*` stack has been removed. The remaining
   `app/Services/Gateway` files are intentionally pre-trust/runtime helpers
   (`FetchGatewayRootCa`, `GatewayApiRuntimeInstaller`, `RootCaFetchResult`).

Manual orchestration status: the active Testing Infrastructure, Saloon
transport, activity foundation, and Node-family blockers for app read-command
porting are cleared. Keep remaining provisioning, WireGuard teardown,
destructive-command E2E, and node activity-metadata follow-ups explicit, but
they do not block the App read workstream.

#### Recently Cleared Manual Items

1. `E2E-IMAGE-WALLTIME-1` (todo 279/298) is measured and instrumented. The
   Incus warm rebuild still misses the old 3-minute target, but this is no
   longer an app-read blocker now that Docker is the default feature E2E lane.
2. `E2E-PROVISION-VERIFY-1` (todo 290) is complete:
   `composer test:e2e:provision` passed with 9 tests and 161 assertions.
3. `NODE-REVOKE-FWD-1` (todo 302) is complete: configured control callers
   forward through `RevokeNodeRequest` and preserve structured gateway API
   errors.
4. `NODE-REMOVE-FWD-1` (todo 303) is complete: configured control callers
   forward through `RemoveNodeRequest` and preserve structured gateway API
   errors.
5. `NODE-READ-AUTH-1` (todo 304) is complete: `node:list`/`node:show` preserve
   gateway authorization failures, and `node:show` covers default development
   app-node forwarding.

#### First-Gateway Provisioning Split Candidates

Todo 255 is intentionally broad and should be refreshed or replaced by bounded
worker todos before dispatch. Use these candidates in order when provisioning
work becomes the active lane:

0. `NODENEW-WG-FOUNDATION-1` (todo 271) is merged: `WireGuardPeer`,
   `wireguard_peers`, factories, and `WireGuardKeyGenerator` are available for
   the enrollment slice.
1. `NODENEW-WIREGUARD-ENROLL-1` (todo 268) is implemented: first-gateway
   bootstrap generates gateway/control keys, persists gateway-side peer rows
   plus the local initiating-control peer, writes the gateway `wg-orbit` config
   through `orbit:internal:bootstrap-gateway-local`, and starts/enables the
   interface. The paired `e2e-provision` gate passed in the full provision
   lane.
2. `NODENEW-GATEWAY-API-VERIFY-1` (todo 283) is implemented: first-gateway
   bootstrap verifies `GET /api/me` over the gateway WireGuard IP after
   WireGuard, CA trust, and local gateway settings are in place. The paired
   `e2e-provision` gate passed in the full provision lane.
3. `NODENEW-GATEWAY-CA-VERIFY-1` (todo 272) is implemented: first-gateway
   bootstrap verifies and installs gateway CA trust, stores local trust
   metadata, exposes JSON trust evidence, and keeps repeat runs idempotent.
   The paired provisioning E2E gate passed in the full provision lane.
4. `NODENEW-PLATFORM-DETECT-1` (todo 274) is implemented: gateway and local
   control platform identifiers are detected and persisted during first-gateway
   bootstrap. A dedicated platform-detection provisioning gate remains
   non-blocking follow-up coverage.
5. `NODENEW-JSON-SUCCESS-1` is implemented: first-gateway bootstrap and
   compatible repeat/convergence JSON success payloads now reflect the
   documented state, including no SSH transport claim for already-provisioned
   convergence.

#### Gateway Write-Forwarding Chain (Separate Track)

Once `NODE-READ-FWD-1`, `E2E-NODE-READ-1`, and
`FAMILY-REVIEW-NODE-READ-1` are verified or explicitly deferred here, the same
caller-role-branch + typed-request pattern can be applied to the write
commands. Order matters because each adds a new write API endpoint:

1. `NODE-API-UPDATE-1` is implemented: gateway-side `PUT /api/nodes/{name}` +
   `UpdateNodeRequest`.
2. `NODE-UPDATE-FWD-1` is implemented: configured control callers forward
   `node:update` through `UpdateNodeRequest`, preserve structured gateway API
   errors, avoid local target-node writes, and have paired Docker feature E2E
   coverage.
3. `NODE-API-DEFAULT-1` is implemented: gateway-side `GET|PUT|DELETE
   /api/nodes/default` + `DefaultNodeRequest`.
4. `NODE-DEFAULT-FWD-1` is invalidated/deferred: the current command contract
   keeps `node:default` as a control-node-local preference. Do not wire command
   forwarding unless the command docs are intentionally changed first.
5. `NODE-API-GRANT-1` is implemented: gateway-side `POST /api/nodes/grant` +
   `GrantNodeRequest`.
6. `NODE-GRANT-FWD-1` is implemented: configured control callers forward
   `node:grant` through `GrantNodeRequest`; paired Docker feature E2E coverage
   verifies the forwarded grant and read-back path.
7. `NODE-API-REVOKE-1` and `NODE-REVOKE-FWD-1` are implemented: configured
   control callers forward through `RevokeNodeRequest`, preserve structured
   gateway API errors, and do not require local target-node rows.
8. `NODE-API-REMOVE-1` and `NODE-REMOVE-FWD-1` are implemented: configured
   control callers forward through `RemoveNodeRequest`, preserve structured
   gateway API errors, and do not require local target-node rows. WireGuard
   peer teardown and DNS cleanup stay tracked as later destructive cleanup
   follow-ups.

Do not create future FWD-* todos until the matching API-* todo is on `main`.
Do not create more than 2 of these chains in flight at once.

#### App Workstream Entry Point (Unblocked)

Unblocked. Saloon node-family migration and the activity-log foundation are now
on `main`. App family ports must use the Saloon `GatewayConnector` /
`GatewayRequest` / typed DTO pattern, declare a `## Activity Logging` section
in each command's `technical/1_<command>.md` per
[`activity-concepts.md`](commands/17_activity/activity-concepts.md), and add
the command to `ActivityLoggingContractRule::ENFORCED_COMMANDS` as the
section lands.

The first app slices should stay read-only and use Docker-backed feature E2E
before any app write/destructive commands are created:

1. `APP-ABSTRACTION-1` — create `docs/abstractions/5_app.md` from app command
   docs, old app evidence, and cross-cutting patterns before any app
   implementation todo is promoted. **Implemented:** see
   `docs/abstractions/5_app.md`.
2. `APP-SCHEMA-1` — port app schema and Eloquent model for the apps table.
   **Implemented:** app registry schema and `App` model/factory are on `main`
   with focused model coverage. Current docs:
   `docs/commands/5_app/README.md` and `docs/commands/5_app/app-concepts.md`.
   Old evidence: `../orbit-old-may/app/Models/App.php`.
3. `APP-API-LIST-1` — gateway-side `GET /api/apps` + `ListAppsRequest`.
   **Implemented:** gateway API list endpoint, typed Saloon request/DTO,
   activity logging contract, and focused API/request coverage are on `main`.
   Current docs: `docs/commands/5_app/3_app-list`. Old evidence:
   `../orbit-old-may/app/Actions/Apps/ListApps.php` and
   `../orbit-old-may/app/Http/Saloon/Requests/Apps/ListAppsRequest.php`.
4. `APP-LIST-1` — `app:list` command (paired in-memory Pest + ephemeral Pest E2E).
   **Implemented:** command, human/JSON renderers, typed gateway forwarding,
   focused in-memory Pest coverage, and Docker feature E2E coverage are on
   `main`. Docker feature E2E passed with
   `composer test:e2e -- --filter='App(List|Show)'`. Current docs:
   `docs/commands/5_app/3_app-list`. Old evidence:
   `../orbit-old-may/app/Console/Commands/AppListCommand.php`.
5. `APP-API-SHOW-1` — gateway-side `GET /api/apps/{name}` + `ShowAppRequest`.
   **Implemented:** gateway API show endpoint, typed Saloon request/DTO,
   activity logging contract, name-before-hostname resolution, and focused
   API/request coverage are on `main`. Current docs:
   `docs/commands/5_app/4_app-show`. Old evidence:
   `../orbit-old-may/app/Actions/Apps/ShowAppInfo.php` and
   `../orbit-old-may/app/Http/Saloon/Requests/Apps/ShowAppRequest.php`.
6. `APP-SHOW-1` — `app:show` command (paired in-memory Pest + ephemeral Pest E2E).
   **Implemented:** command, human/JSON renderers, typed gateway forwarding,
   focused in-memory Pest coverage, and Docker feature E2E coverage are on
   `main`. Docker feature E2E passed with
   `composer test:e2e -- --filter='App(List|Show)'`. Current docs:
   `docs/commands/5_app/4_app-show`. Old evidence:
   `../orbit-old-may/app/Console/Commands/AppShowCommand.php`.
7. `APP-REMOTE-SHELL-1` — minimal gateway-owned `RemoteShell` foundation for
   app write enactment.
   **Implemented:** `RemoteShell` contract, `SshRemoteShell` implementation,
   structured result/exception types, local-node bash execution, remote SSH
   execution using clean `Node` registry fields (`wireguard_address`,
   `ssh_user`, `host`), focused result/service coverage, and container binding
   are on `main`. Current docs: `docs/commands/5_app/1_app-new`. Old evidence:
   `../orbit-old-may/app/Services/RemoteShell/SshRemoteShell.php`.

Do not create app write commands (`app:new`, `app:remove`, `app:prune`) until
the read pair is verified. Do not create workspace, process, tool, proxy,
Cloudflare, VPN, PHP, or agent IDE implementation todos just because their docs
exist. Those families wait for the node/gateway/app foundations.

#### Hard Blocks

- Keep gateway-family implementation blocked until the node identity and
  first-gateway provisioning prerequisites are clear.
- Keep workspace, process, and downstream families blocked until app read
  foundations exist.
- App read commands are unblocked; keep them read-only until `APP-LIST-1` and
  `APP-SHOW-1` pass their paired Pest + Docker feature E2E gates.
- Keep app write/destructive commands blocked until app read commands and the
  required node write-forwarding/provisioning safety gates are clear.

## Node Workstream

- [x] Convert node command docs into current format.
- [~] Build minimal node registry read commands.
- [~] Complete `node:list` contract gaps:
  - [x] JSON renderer contract.
  - [x] Human renderer contract.
  - [x] `--role` and `--environment` filters.
  - [x] Node doctor technical contract and `NodesProbe` primitives.
  - [x] `--doctor` secondary operation.
  - [x] caller visibility/access-policy behavior.
  - [x] gateway forwarding (control/app CLI callers use typed GatewayConnector;
    E2E gate todo 254 complete).
  - [x] doctor handoff behavior.
- [~] Complete `node:show` contract gaps:
  - [x] modeled `environment`, `platform`, and node agent IDE metadata.
  - [x] JSON renderer contract (envelope shape, field contract, all error codes and metadata).
  - [x] Human renderer contract (field order, grants section, failure prose).
  - [x] caller-role resolution.
  - [x] access-policy authorization.
  - [x] gateway forwarding (control/app CLI callers use typed GatewayConnector;
    E2E gate todo 254 complete).
  - [ ] interactive prompting.
  - [x] default development app-node resolution.
  - [x] real grant metadata for gateway-local and forwarded reads.
- [x] Reconcile `node:register` with product command contracts.
  - **Decision:** Retire as public command. `node:register` is an internal
    bootstrap utility only. See tracker entry above for rationale.
- [~] Port `node:update`.
  - Current implementation: `app/Console/Commands/NodeUpdateCommand.php`
  - Current docs: `docs/commands/1_node/7_node-update`
  - Current tests:
    - `tests/Feature/Commands/NodeUpdateCommandTest.php` (base contract, safety, duplicate flag)
    - `tests/Feature/Commands/Nodes/NodeUpdateCommandTest.php` (command contract)
    - `tests/Feature/Commands/Nodes/NodeUpdateHumanRendererTest.php` (human renderer contract)
    - `tests/Feature/Commands/Nodes/NodeUpdateJsonRendererTest.php` (JSON renderer contract)
    - `tests/E2E/NodeUpdateTest.php` (Docker feature E2E for control-caller gateway forwarding)
  - Bootstrap slice implemented: gateway-local update with progress tree, field validation, role-incompatibility checks, and split contract tests.
  - Gateway forwarding slice implemented: configured control callers forward
    through `GatewayConnector` and typed `UpdateNodeRequest`; gateway API
    structured errors are preserved, and forwarded writes do not require or
    mutate a local target-node row.
  - E2E gate implemented: Docker feature coverage verifies a configured control
    caller updates gateway-owned app-node metadata through the Gateway API and
    reads the persisted intent back through forwarded `node:show`.
  - Contract gaps:
    - interactive input mode (prompting for name and field selection).
    - artifact re-enactment after intent update.
- [x] Port `node:default`.
  - Current implementation: `app/Console/Commands/NodeDefaultCommand.php`
  - Current docs: `docs/commands/1_node/9_node-default`
  - Current tests:
    - `tests/Feature/Commands/NodeDefaultCommandTest.php` (flat base contract)
    - `tests/Feature/Commands/Nodes/NodeDefaultCommandTest.php` (split command contract)
    - `tests/Feature/Commands/Nodes/NodeDefaultHumanRendererTest.php` (human renderer contract)
    - `tests/Feature/Commands/Nodes/NodeDefaultJsonRendererTest.php` (JSON renderer contract)
  - Bootstrap slice implemented: local read/show/set/clear sub-actions, human progress tree, JSON envelope shape, caller role rejection, split contract tests.
  - Gateway API slice implemented: gateway-side show/set/clear endpoints and typed `DefaultNodeRequest`.
    This API exists on `main`, but command forwarding is not part of the active
    product contract.
  - Gateway-visible validation implemented: configured control callers validate
    `set` and discover interactive `choose` options through `ListNodesRequest`
    while keeping default storage local.
  - No command-forwarding todo is currently valid for `node:default` without a
    product-doc change.
- [~] Port `node:grant`.
  - Current implementation: `app/Console/Commands/NodeGrantCommand.php`
  - Current docs: `docs/commands/1_node/5_node-grant`
  - Current tests:
    - `tests/Feature/Commands/Nodes/NodeGrantCommandTest.php` (command contract)
    - `tests/Feature/Commands/Nodes/NodeGrantHumanRendererTest.php` (human renderer contract)
    - `tests/Feature/Commands/Nodes/NodeGrantJsonRendererTest.php` (JSON renderer contract)
    - `tests/E2E/NodeGrantTest.php` (Docker feature E2E for control-caller gateway forwarding)
  - Bootstrap slice implemented: gateway-local grant creation, idempotence, node-not-found validation, self-grant policy enforcement, caller role rejection, human and JSON renderer contracts, split contract tests.
  - Gateway API prerequisite implemented: `POST /api/nodes/grant` plus typed
    `GrantNodeRequest`.
  - Gateway forwarding slice implemented: configured control callers forward
    through `GatewayConnector` and typed `GrantNodeRequest`; gateway API
    structured errors are preserved, and forwarded grants do not require or
    mutate a local target-node row.
  - E2E gate implemented: Docker feature coverage verifies a configured control
    caller creates a gateway-owned node access grant through the Gateway API and
    reads the grant back through forwarded `node:show`.
- [~] Port `node:revoke`.
  - Current implementation: `app/Console/Commands/NodeRevokeCommand.php`
  - Current tests:
    - `tests/Feature/Commands/Nodes/NodeRevokeCommandTest.php` (command contract)
    - `tests/Feature/Commands/Nodes/NodeRevokeHumanRendererTest.php` (human renderer contract)
    - `tests/Feature/Commands/Nodes/NodeRevokeJsonRendererTest.php` (JSON renderer contract)
    - `tests/Feature/Commands/Nodes/NodeRevokeInteractiveInputModeTest.php` (interactive input mode contract)
    - `tests/E2E/NodeRevokeTest.php` (Docker feature E2E for control-caller gateway forwarding)
  - Bootstrap slice implemented: gateway-local grant revocation, idempotence, node-not-found validation, self-lockout detection, destructive consent (`--force`, interactive confirmation), caller role rejection, human progress tree and JSON renderer contracts, split contract tests.
  - Gateway API prerequisite implemented: `POST /api/nodes/revoke` plus typed
    `RevokeNodeRequest`.
  - Gateway forwarding slice implemented: configured control callers forward
    through `GatewayConnector` and typed `RevokeNodeRequest`; gateway API
    structured errors are preserved, and forwarded revocations do not require
    or mutate a local target-node row.
  - E2E gate implemented: Docker feature coverage verifies a configured control
    caller removes a gateway-owned node access grant through the Gateway API and
    reads the removed grant state back through forwarded `node:show`.
  - Contract gaps:
    - Interactive prompt testing in PHPUnit/Pest is limited by non-TTY environment; confirmation decline and prompt abort behavior are covered by command logic but not fully exercised via automated prompts.
- [~] Port `node:remove`.
  - Files:
    - `app/Console/Commands/NodeRemoveCommand.php` (gateway-local bootstrap slice)
    - `tests/Feature/Commands/Nodes/NodeRemoveCommandTest.php` (command contract)
    - `tests/Feature/Commands/Nodes/NodeRemoveHumanRendererTest.php` (human renderer contract)
    - `tests/Feature/Commands/Nodes/NodeRemoveJsonRendererTest.php` (JSON renderer contract)
    - `tests/Feature/Commands/Nodes/NodeRemoveInteractiveInputModeTest.php` (interactive input mode contract)
    - `tests/E2E/NodeRemoveTest.php` (Docker feature E2E for control-caller gateway forwarding)
  - Bootstrap slice implemented: gateway-local node removal, grant cascade (consumer and serving directions), node-not-found validation (NOT idempotent), gateway-node rejection, destructive consent (`--force`, interactive confirmation), caller role rejection, human progress tree and JSON renderer contracts, split contract tests.
  - Gateway API prerequisite implemented: `DELETE /api/nodes/{name}` plus typed
    `RemoveNodeRequest`.
  - Gateway forwarding slice implemented: configured control callers forward
    through `GatewayConnector` and typed `RemoveNodeRequest`; gateway API
    structured errors are preserved, and forwarded removals do not require or
    mutate a local target-node row.
  - E2E gate implemented: Docker feature coverage verifies a configured control
    caller removes a gateway-owned app-node record through the Gateway API and
    reads the removed state back through forwarded `node:show`.
  - Contract gaps:
    - WireGuard peer teardown (peer model/migration exist but teardown logic not yet implemented; `wireguard_peer_removed: false` in JSON response).
    - DNS mapping cleanup for dev-app nodes (requires gateway API DNS support).
    - Interactive prompt testing in PHPUnit/Pest is limited by non-TTY environment; confirmation decline and prompt abort behavior are covered by command logic but not fully exercised via automated prompts.
- [~] Port `node:agent-ide`.
  - Current implementation: `app/Console/Commands/NodeAgentIdeCommand.php`
  - Current docs: `docs/commands/1_node/10_node-agent-ide`
  - Current tests:
    - `tests/Feature/Commands/Nodes/NodeAgentIdeCommandTest.php` (command contract)
    - `tests/Feature/Http/Api/NodeAgentIdeControllerTest.php` (gateway API and activity)
    - `tests/Feature/Commands/Nodes/NodeShowJsonRendererTest.php` (node-show agent IDE metadata)
    - `tests/Feature/Http/Api/NodeShowControllerTest.php` (gateway API show metadata)
  - Bootstrap slice implemented: gateway-local set/clear/converged writes,
    configured control forwarding through `GatewayConnector` and typed
    `SetNodeAgentIdeRequest`, gateway API endpoint, unsupported-adapter and
    node-not-found errors, app-caller denial, node-show metadata, and activity
    logging. Docker feature E2E verifies the configured control-caller
    forwarding path and node-show metadata convergence.
  - Contract gaps:
    - interactive input prompting.
    - extension-registered adapter registry beyond the core adapters
      (`opencode`, `polyscope`) and reserved `none` token.
- [~] Port `node:new`.
  - [x] Bootstrap host installer exists and is used before Orbit runs on a
    fresh gateway host.
  - [x] First-gateway command path validates required non-interactive input.
  - [x] First-gateway command path ships current Orbit source to the target over
    SSH and runs the installer there.
  - [x] First-gateway command path records bootstrap gateway and local control
    registry rows.
  - [x] First-gateway bootstrap creates/verifies a steady-state runtime user
    (`orbit`) through the bootstrap SSH user and installs Orbit under that user.
  - [x] First-gateway ephemeral E2E lane (`composer test:e2e:provision --filter='NodeNewGateway'`) verifies
    end-to-end provisioning against disposable Incus VMs.
  - [x] First-gateway WireGuard enrollment E2E lane (`composer test:e2e:provision -- --filter='NodeNewWireGuard'`)
    verifies gateway/control WireGuard interfaces, gateway registry peer rows,
    gateway API reachability over WireGuard, and idempotent gateway convergence.
  - [x] First-gateway bootstrap invokes gateway-local internal command over SSH
    to initialize gateway node identity (`is_local=true`) and generate root CA.
  - [x] First-gateway bootstrap provisions the gateway-local Orbit API runtime
    using Caddy and an Orbit-owned PHP-FPM pool before verifying `/api/me` from
    the initiating control node.
  - [x] First-gateway bootstrap captures gateway root CA from remote command
    output and stores it locally for control-node trust.
  - [ ] Interactive input mode.
  - [ ] Gateway-connected forwarding from configured control nodes.
  - [ ] Gateway-local app and control enrollment paths.
  - [x] Real platform detection for first-gateway bootstrap (todo 274).
  - [x] Full documented JSON success state after WireGuard/API work lands.
- [~] Restore node provisioning support:
  - [~] SSH bootstrap
  - [~] WireGuard enrollment
    - [x] `WireGuardPeer` model, migration, and `WireGuardKeyGenerator` service (todo 271).
    - [x] Node enrollment hook and gateway interface configuration (todo 268).
  - [~] gateway registry writes
  - [~] local node role and identity persistence
  - [ ] Orbit API vhost provisioning
  - [ ] Orbit PHP-FPM pool provisioning
  - [ ] gateway-to-node SSH trust model
- [ ] Distribute SSH trust to the runtime user so control nodes can SSH as
  `orbit` after first-gateway provisioning.
- [!] Restore ephemeral node E2E before treating provisioning and host-mutation
  flows as fully verified.

## Gateway Workstream

- [x] Convert gateway command docs into current format.
- [~] Port gateway trust/settings foundation (GATEWAY-2 bootstrap slice).
  - [x] `LocalGatewaySettings` single-row Eloquent model with `current()` accessor.
  - [x] `TrustStoreInstaller` interface with macOS/Linux implementations.
  - [x] `FetchGatewayRootCa` bootstrap-safe CA fetch service.
- [~] Port `gateway:add`.
  - [x] Control-node local onboarding flow: derive/verify gateway IP, fetch CA, install trust, verify `/api/me`, persist settings, idempotent convergence.
  - [x] Caller role resolution from local node registry (`Node::where('is_local', true)->value('role')`).
  - [x] Human renderer with progress tree shape per contract.
  - [x] JSON renderer with envelope shape per contract.
  - [x] Split contract tests: caller role, input contract, interactive/non-interactive input modes, JSON/human renderers.
  - [ ] WireGuard IP derivation from active network interfaces (bootstrap gap: currently requires explicit `gateway_ip` argument).
  - [ ] Local node context flush after persistence (bootstrap gap: `LocalNodeContext` service not yet in clean repo).
  - [ ] Ephemeral E2E lane for control-node `gateway:add` against real gateway VM.
- [x] Port `gateway:trust`.

## Gateway CA Workstream

- [~] Gateway root CA service.
  - [x] `OrbitCaService` generates, reads, and issues from a local gateway-root CA.
  - [x] Truthful CA generation hook in `node:new --role=gateway` first-gateway bootstrap.
    - Gateway-local internal command `orbit:internal:bootstrap-gateway-local` initializes
      the gateway's database identity (`is_local=true`, `role=gateway`, `status=active`)
      and calls `OrbitCaService::ensureRootCa()`.
    - `node:new --role=gateway` invokes this command over SSH after remote Orbit
      installation succeeds, captures the root CA cert, stores it locally,
      verifies local trust installation, and persists gateway trust metadata.
    - Focused tests cover CA generation, idempotence, node demotion, invalid-PEM
      rejection, trust-store install evidence/failure, idempotent repeat runs,
      and the full `node:new` bootstrap invocation path.

## DNS Workstream

- [x] Convert DNS command docs into current format.
- [x] Port `dns:resolve-tld`.
  - Current implementation: `app/Console/Commands/DnsResolveTldCommand.php`
  - Current service: `app/Services/Dns/LocalResolver.php`
  - Current tests:
    - `tests/Feature/Commands/Dns/DnsResolveTldCommandTest.php` (base contract, caller role, validation, idempotence, unsupported platform, safety)
    - `tests/Feature/Commands/Dns/DnsResolveTldNonInteractiveInputModeTest.php` (non-interactive mode, missing input, forbidden input, invalid values)
    - `tests/Feature/Commands/Dns/DnsResolveTldJsonRendererTest.php` (JSON envelope, success/error shapes, every error code, refresh-failed partial data)
    - `tests/Feature/Commands/Dns/DnsResolveTldHumanRendererTest.php` (human progress trees, success/failure prose, no JSON envelopes)
  - Contract gaps:
    - Interactive input mode prompts are covered by command logic but not fully exercised via automated TTY prompts (standard PHPUnit/Pest limitation).
    - Ephemeral E2E lane (`composer test:e2e:provision --filter='DnsResolveTld'`) is tracked as todo 245 (DNS-LANE-RESOLVE-TLD-1); deferred until E2E harness lane is authored.
    - Linux backend support is intentionally deferred; only macOS dnsmasq backend is implemented.
- [x] Port `dns:list`.
  - Current implementation: `app/Console/Commands/DnsListCommand.php`
  - Current service: `app/Services/Dns/LocalResolver.php`
  - Current tests:
    - `tests/Feature/Commands/Dns/DnsListCommandTest.php` (base contract, caller role, local resolver read behavior, empty result success, unsupported platform, safety)
    - `tests/Feature/Commands/Dns/DnsListJsonRendererTest.php` (JSON envelope, success metadata, empty result shape, resolver entry shape, error envelopes)
    - `tests/Feature/Commands/Dns/DnsListHumanRendererTest.php` (human table, empty result prose, failure prose, no progress tree, no JSON envelopes)
    - `tests/E2E/DnsListTest.php` (Incus-backed Linux control-node feature gate)
  - Old evidence:
    - `../orbit-old-may/app/Console/Commands/DnsListCommand.php`
    - `../orbit-old-may/app/Actions/Dns/ListDnsMappings.php`
    - `../orbit-old-may/app/Concerns/ReadsDnsmasqConfig.php`
  - Implemented: control-only caller-role gate, read-only local
    resolver listing from Orbit-managed dnsmasq override files, JSON renderer,
    human renderer, empty-result success, unsupported-platform failure, and
    resolver-read failure.
  - Product decision: current `dns:list` follows the clean DNS contract and
    reads caller-local resolver overrides. It does not port old Orbit's
    gateway/container DNS query path because current DNS docs explicitly keep
    this command local and away from gateway-owned development DNS mappings.
  - Verification:
    - In-memory Pest: `php artisan test --compact tests/Feature/Commands/Dns`.
    - Feature E2E: `composer test:e2e -- --filter='DnsList'`.
      This gate installs the current checkout into the disposable control role
      and invokes `php artisan dns:list --json` from that checkout, leaving the
      baked `orbit` symlink and reusable topology baselines unchanged.

## Activity Logging Workstream

Activity logging is cross-cutting infrastructure that every command and API
endpoint participates in. Product authority:
[`docs/commands/17_activity/activity-concepts.md`](commands/17_activity/activity-concepts.md).
The doctrine requires every ported command's `technical/1_<command>.md` to
declare a `## Activity Logging` section. The
`command_docs.activity_logging_contract` lint rule enforces the section on
an explicit allowlist that grows as commands backfill, so the rule and the
fleet converge from the activity family outward.

The old repo used `spatie/laravel-activitylog` v4 with Orbit-specific
attribution metadata on every write command and many read commands.

### Foundation

- [x] `ACTIVITY-FOUNDATION-1` — install and configure
  `spatie/laravel-activitylog` v4.12.3, publish config/migrations, and
  establish the activity log table.
- [x] `ACTIVITY-CORRELATION-1` — port `ActivityLogCorrelation` service and
  `X-Orbit-Request-Id` header propagation (gateway API and CLI command
  correlation). Prerequisite for the Saloon `HasCorrelationHeader` plugin.
- [x] `ACTIVITY-CLI-TRAITS-1` — define the activity-log interface/traits
  that commands implement to declare their attribution metadata. Old
  evidence: `../orbit-old-may/app/Console/Commands/` (search
  `activityLogType`).

### Doctrine And Family Split

- [x] `ACTIVITY-FAMILY-SPLIT-1` — split `activity:list` and `activity:show`
  into the new top-level `17_activity` family. Adds `activity-concepts.md`
  doctrine, reconciles `docs/CONCEPTS.md`, `11_operation/README.md`,
  `11_operation/operation-concepts.md`, and registers `activity` in
  `NonStateDomainHandoffRule`.
- [x] `ACTIVITY-EFFECT-DESTRUCTIVE-1` — restore `destructive` as a third
  effect alongside `read`/`write` in the activity model. Adds
  `--effect=<read|write|destructive>` filter to `activity:list` and
  expands the JSON renderer enum on both activity commands.
- [x] `ACTIVITY-LOGGING-LINT-1` — add
  `command_docs.activity_logging_contract` rule with allowlist
  `[activity-list, activity-show]` plus Pest coverage in
  `tests/Feature/DocsLinter/ActivityLoggingContractRuleTest.php`. The
  allowlist grows per family as commands backfill.

### Loggable Contract Realignment

The doctrine reshapes the Loggable contract vocabulary:

- Old method names: `activityLogType()` (Read|Write), `activityLogAction()`
  (string), `activityLogSubject()`, `activityLogProperties()`,
  `activityLogDescription()`.
- New doctrine names: `effect()` (read|write|destructive), `type()` (action
  string like `node.granted`), `subject()`, `properties()`, `description()`.

The vocabulary swap is intentional: doctrine `effect` = old `Type`, doctrine
`type` = old `Action`. Aligned with the `activity:list` tech contract column
names and adds `destructive` to the effect set.

- [x] `ACTIVITY-LOGGABLE-RENAME-1` — rename Loggable contract surface in PHP
  (`App\Contracts\Loggable`, traits, controllers) to the doctrine names.
  Keep old method names as thin proxies until callers migrate, then remove.
- [x] `ACTIVITY-EFFECT-DESTRUCTIVE-IMPL-1` — extend the activity-log effect
  enum to support `destructive` and surface the new value in the gateway
  response payload and `activity:list --effect` filter.
  - [x] PHP `ActivityLogType` enum and activity middleware logging now support
    `destructive`.
  - [x] Gateway API activity history reads now surface `effect` in the response
    payload and support destructive filtering through `GET /api/activity`.
  - [x] CLI `activity:list --effect=destructive` now filters locally for gateway
    callers and forwards through the typed gateway API request for control/app
    callers.

### Per-Command Tech Contract Backfill

Every converted command's `technical/1_<command>.md` gains a complete
`## Activity Logging` section per
`docs/commands/17_activity/activity-concepts.md`: `Type`, `Effect`,
`Subject`, `Properties`, and `Description`, or an explicit "does not emit"
declaration with reason. Each command's backfill adds the command name to
`ActivityLoggingContractRule::ENFORCED_COMMANDS`. Backfilling a family
should be sequenced inside the family's port slice, not as a separate
sweep, so the section reflects the same per-command product decisions
that produced the controller's Loggable wiring.

- [x] `17_activity` (`activity:list`, `activity:show` — allowlisted).
- [x] `1_node` (`node:new`, `node:list`, `node:show`, `node:update`,
  `node:default`, `node:grant`, `node:revoke`, `node:remove`,
  `node:agent-ide`).
- [ ] `2_gateway`.
- [ ] `3_tool`.
- [ ] `4_firewall`.
- [ ] `5_app`.
- [ ] `6_workspace`.
- [ ] `7_process`.
- [ ] `8_proxy`.
- [ ] `9_schedule`.
- [ ] `10_deploy`.
- [ ] `11_operation` (`update`, `update:all`, `doctor`, `profile`).
- [ ] `12_cf`.
- [ ] `13_vpn`.
- [ ] `14_php`.
- [ ] `15_agent-ide`.
- [ ] `16_dns`.

### Implementation Slice

- [x] `ACTIVITY-NODE-FAMILY-1` — add activity logging to the node family
  while migrating node commands to Saloon. `node:grant`, `node:revoke`,
  `node:remove`, `node:update`, and `node:default` are the first
  candidates. Pair each command with its tech-contract Activity Logging
  backfill.
  - [x] `node:list` and `node:show` read endpoints now declare their
    `## Activity Logging` tech contracts; `node:show` implements the
    gateway Loggable contract and both commands are enforced by
    `ActivityLoggingContractRule`.
  - [x] `node:grant` now declares its `## Activity Logging` tech contract,
    emits `effect=write` through the gateway Loggable contract, and is
    enforced by `ActivityLoggingContractRule`.
  - [x] `node:default` now declares its `## Activity Logging` tech contract,
    emits read/write metadata for show/set/clear through the gateway Loggable
    contract, and is enforced by `ActivityLoggingContractRule`.
  - [x] `node:update` now declares its `## Activity Logging` tech contract,
    emits target-node and changed-field metadata through the gateway Loggable
    contract, and is enforced by `ActivityLoggingContractRule`.
  - [x] `node:revoke` now declares its `## Activity Logging` tech contract,
    emits `effect=destructive` grant-revocation metadata through the gateway
    Loggable contract, and is enforced by `ActivityLoggingContractRule`.
  - [x] `node:remove` now declares its `## Activity Logging` tech contract,
    emits `effect=destructive` removal metadata through the gateway Loggable
    contract, and is enforced by `ActivityLoggingContractRule`.
  - [x] `node:new` now declares its `## Activity Logging` tech contract,
    emits `effect=write` metadata through the gateway API `POST /api/nodes`
    Loggable contract, and is enforced by `ActivityLoggingContractRule`.
    First-gateway local CLI emission remains separate because that path can run
    before a gateway activity sink exists.
  - [x] `node:agent-ide` now declares its `## Activity Logging` tech contract,
    emits `effect=write` metadata through the gateway API
    `POST /api/nodes/{name}/agent-ide` Loggable contract, and is enforced by
    `ActivityLoggingContractRule`.
- [x] `ACTIVITY-READ-AUDIT-1` — resolved by doctrine. Read commands
  (`*:list`, `*:show`) emit with `effect=read`. A specific read may
  declare `does not emit` only when noise dominates audit value; the
  exception belongs in the command's `## Activity Logging` section with
  a reason.

### Test Gates

- [x] Pest: Loggable contract per Loggable controller plus correlation
  generation through `LogActivity` middleware. Foundation coverage lives in
  `tests/Feature/Http/Middleware/LogActivityTest.php`; current activity, app,
  and node Loggable controllers have focused API tests alongside their
  implementation slices.
- [x] Pest: gateway API activity list read supports destructive filtering,
  normalized JSON metadata, `has_more`, validation, and `activity.listed`
  logging through `tests/Feature/Http/Api/ActivityListControllerTest.php`.
- [x] Pest: gateway API activity show read supports selected activity details,
  related entries, not-found and validation failures, and `activity.shown`
  logging through `tests/Feature/Http/Api/ActivityShowControllerTest.php`.
- [x] Pest: `activity:list` and `activity:show` command tests under
  `tests/Feature/Commands/Activity/Activity*Test.php` (the moved tech
  contracts already point at the `Activity` namespace).
  - [x] `activity:list` command coverage for local gateway reads, typed gateway
    forwarding, destructive filtering, validation, empty human output, and typed
    gateway request DTO parsing.
  - [x] `activity:show` command coverage for local gateway detail reads,
    typed gateway forwarding, validation, not-found, authorization failures,
    human detail output, and typed gateway request DTO parsing.
- [x] E2E gate: Docker feature read of `activity:list` from control through
  the gateway API after seeding a few gateway activity entries; read-only from
  the caller perspective. Standing live-node smoke wording was stale and was
  replaced because `TESTING.md` sunsets live infrastructure lanes.

## App Workstream

Unblocked for read-only slices. See `App Workstream Entry Point` in
`Todo Pipeline Hints` for sequencing and verification constraints.

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
- [~] Port `app:register`.
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
  - [ ] Interactive input mode and human renderer progress tree.
  - [ ] Production activation retry warnings and E2E registration/adoption gate.
- [ ] Port `app:root`.
- [ ] Port `app:remove`.
- [ ] Port `app:prune`.
- [ ] Port `app:agent-ide`.
- [ ] Decide whether legacy app helper commands such as `app:link`,
  `app:secure`, `app:status`, `app:sync`, and scheduler commands should get
  converted docs or stay retired.

## Workspace Workstream

- [x] Convert workspace command docs into current format.
- [x] Reshape workspace docs to reference inherited runtime units rendered
  as Supervisor programs by the runtime backend. See
  [`2026-05-05-supervisor-runtime-backend-plan.md`](2026-05-05-supervisor-runtime-backend-plan.md).
- [ ] Port workspace schema and models.
- [ ] Port workspace lifecycle commands.
- [ ] Port workspace setup and teardown step commands.
- [ ] Port workspace history and log commands.
- [ ] Port workspace progress stream behavior.

## Process Workstream

- [x] Convert process command docs into current format.
- [x] Reshape process docs around runtime backend (Supervisor) and runtime
  unit vocabulary. See
  [`2026-05-05-supervisor-runtime-backend-plan.md`](2026-05-05-supervisor-runtime-backend-plan.md).
- [x] Port process schema and models.
- [ ] Port process add/edit/remove/list commands.
- [ ] Port process start/stop/restart commands against Supervisor.
- [ ] Port process log command against Supervisor stdout/stderr capture.
- [ ] Port process exit hook support if still part of the product contract.

## Schedule Workstream

- [x] Convert schedule command docs into current format.
- [x] Reshape schedule docs around the Orbit Scheduler resident daemon. See
  [`2026-05-05-supervisor-runtime-backend-plan.md`](2026-05-05-supervisor-runtime-backend-plan.md).
- [ ] Port schedule schema, models, and run-history table.
- [ ] Port schedule add/list/show/remove commands.
- [ ] Port schedule run command (manual fire / on-demand tick).
- [ ] Port schedule logs command against scheduler-captured stdout/stderr.
- [ ] Port `orbit-scheduler` Artisan-command daemon and Supervisor program
  rendering.
- [ ] Port scheduler heartbeat reporting and run-history intake endpoint.
- [ ] Port schedule doctor probe and fix map.

## Runtime Backend And Scheduler Workstream

The runtime backend (Supervisor) and the Orbit Scheduler are introduced as
product behavior in the doc reshape and require implementation work shared
across the process and schedule families.

- [x] Document the runtime backend (Supervisor) and Orbit Scheduler in
  blueprint, mission, building blocks, concepts, process docs, schedule
  docs, and workspace docs. See
  [`2026-05-05-supervisor-runtime-backend-plan.md`](2026-05-05-supervisor-runtime-backend-plan.md).
- [x] Cross-family coherence cleanup: add `supervisor` as a Required
  Baseline tool catalog entry (closing the doctor handoff loop from
  process / schedule), document the host-services-as-Supervisor-peers
  rule, add deploy `Cross-family invocation` so deploy steps can call
  `process:restart`, document `app:new` does not auto-create the
  Laravel scheduler, and distinguish the Orbit Scheduler daemon from
  the `orbit_scheduler` Supervisor program in `CONCEPTS.md`.
- [x] Mitigate BLUEPRINT / BUILDING-BLOCKS drift on the runtime backend +
  scheduler with explicit co-edit cross-references and a one-line
  contract / implementation split note in both files.
- [x] Make per-workspace Supervisor config explicit: each workspace's
  inherited runtime units render as separate Supervisor programs with
  workspace-specific working directory, environment, and log paths
  derived from the parent app's process definition.
- [ ] Add Supervisor installation to gateway and app node provisioning.
- [ ] Add the runtime backend reachability probe shared by process and
  schedule doctor.
- [ ] Add the Supervisor program renderer shared by process and schedule
  enactment.
- [ ] Add the `orbit-scheduler` Artisan-command daemon.
- [ ] Add scheduler local-state schema (locks, heartbeat, last-sync).
- [ ] Add scheduler-to-gateway authentication using the existing WireGuard
  node identity.
- [ ] Add the gateway run-history intake endpoint and typed request.
- [ ] Docker E2E base image runs `supervisord -n` as PID 1 (under `tini`)
  and ships pre-installed Supervisor and `orbit_scheduler` program files.
- [ ] Add Docker E2E coverage for runtime backend behavior and scheduler
  liveness.
- [ ] Add Incus E2E coverage where host init or VM-only behavior is part of
  the assertion.

## Gateway API Client And Transport Workstream

### GATEWAY-API-0 Decision: Gateway API Client Transport

**Status:** Reversed — adopt `saloonphp/saloon` as the gateway API transport.

**Original decision (superseded):** thin `GatewayClient` wrapper over Laravel's
`Http` facade with a hand-rolled `GatewayRequest` interface,
`GatewayRequestSender`, and `GatewayResponseParser`.

**Version note:** `saloonphp/saloon` v4.0.0 is the selected dependency. The plan
originally targeted Saloon v3, but Packagist security advisories blocked v3
resolution against PHP 8.5 / Laravel 13. v4.0.0 resolves cleanly and provides
the same connector/request/DTO architecture.

**Reversal rationale:**

1. **The hand-rolled abstraction was reinventing Saloon poorly.** The clean repo's
   `GatewayRequest` interface (`method()/path()/query()/data()`) is a literal
   subset of Saloon's `Request` shape, with weaker mocking, no plugin pipeline,
   no typed response DTOs, and no community familiarity.

2. **Cost of the abstraction was already paid.** With 7 typed requests + a
   sender + an envelope parser + an interface in place, we were carrying
   abstraction cost equivalent to a Saloon footprint without any of its
   benefits. The cheapest moment to switch was while the surface was still
   small — exactly the moment we were in.

3. **Off-the-shelf > home-grown for proven patterns.** Saloon is the de facto
   standard for typed HTTP clients in Laravel. New contributors recognize it.
   Plugin ecosystems (logging, retries, OAuth) are first-class. The "we don't
   need a taxonomy yet" framing in the original decision cuts the other way:
   if a taxonomy is inevitable, build it on the well-trodden tool.

4. **Typed responses unlock real value.** Saloon's `createDtoFromResponse()`
   replaces `array` plumbing with typed DTOs at every caller — caller code
   reads `$dto->nodes` instead of `$response->data()['nodes'] ?? []`.

**Implementation footprint:**

- `App\Http\Gateway\GatewayConnector` — single connector, base URL + CA verify
  + correlation header plugin from `LocalGatewaySettings::current()`
- `App\Http\Gateway\GatewayRequest` — abstract base with envelope-aware
  `hasRequestFailed()` / `getRequestException()` / `unwrapData()` helpers
- `App\Http\Gateway\GatewayApiException` — thrown on envelope errors
- `App\Http\Gateway\Plugins\HasCorrelationHeader` — Saloon plugin trait for
  `X-Orbit-Request-Id` and `X-Orbit-Client` headers
- `App\Http\Gateway\Requests\<Family>\` — per-endpoint typed Saloon requests
- `App\Http\Gateway\Responses\<Family>\` — typed response DTOs

**Out of scope for the migration:**

- `FetchGatewayRootCa` stays on `Http`. It runs before the CA exists, has
  unique connector requirements (no verify, redirect handling), and gains
  nothing from a Saloon migration.
- `GatewayApiRuntimeInstaller` is a server-side install helper, not a client.

**Reusable implementation guidance:** see `docs/abstractions/cross-cutting.md`
for the Saloon-based gateway transport pattern.

### Remaining Workstream Items

- [x] Decide the clean-rebuild transport approach before adding packages.
- [x] Create thin `GatewayClient` wrapper (superseded by Saloon and removed).
- [x] Port gateway API envelope conventions.
- [x] Port request correlation header support.
- [x] Port typed gateway request sender.
- [x] Port WireGuard identity middleware.
- [x] Port `/api/me`.
- [x] Migrate gateway transport from hand-rolled `GatewayClient` /
  `GatewayRequestSender` to Saloon (`saloonphp/saloon` v4.0.0). Single
  `GatewayConnector` with abstract `GatewayRequest` base handles envelope
  unwrapping and typed `GatewayApiException`. Per-endpoint Saloon `Request`
  subclasses return typed DTOs from `App\Http\Gateway\Responses\<Family>\`.
- [x] Port node API controllers and typed client requests.
  - Implemented with Saloon request/DTO classes for list, show, create,
    grant, revoke, remove, update, and default-node API shapes.
  - Command forwarding paths covered: `node:list`, `node:show`, `node:new`
    app-node creation forwarding, `node:grant`, `node:revoke`, `node:remove`,
    `node:update`, and `node:default` app-node discovery/validation.
  - The old hand-rolled client/request/parser tests have been deleted in favor
    of `tests/Unit/Http/Gateway/Requests/Nodes/*` plus command feature tests
    using Saloon `MockClient`.
- [ ] Port app API controllers and typed client requests.
- [ ] Port workspace API controllers and typed client requests.
- [ ] Port process API controllers and typed client requests.
- [ ] Port tool/service API controllers and typed client requests after tool
  docs are converted.
- [ ] Port doctor API controllers and typed client requests after doctor docs
  are converted.
- [ ] Port long-running SSE progress primitives.

## State Families And Doctor Workstream

- [x] Port family inventory from the blueprint into current docs before
  implementation.
  - [x] Node-family public contract and technical `NodesProbe` contract.
  - [x] App family inventory: `docs/commands/5_app/app-doctor.md`.
  - [x] Workspace family inventory: `docs/commands/6_workspace/workspace-doctor.md`.
  - [x] Process family inventory: `docs/commands/7_process/process-doctor.md`.
  - [x] Proxy family inventory: `docs/commands/8_proxy/proxy-doctor.md`.
  - [x] Firewall-rule family inventory: `docs/commands/4_firewall/firewall-doctor.md`.
  - [x] Tool family inventory: `docs/commands/3_tool/tool-doctor.md`.
  - [x] Schedule family inventory: `docs/commands/9_schedule/schedule-doctor.md`.
  - [x] Global `doctor` technical contract references all eight family
    contracts.
- [~] Port node doctor contracts and checks.
  - [x] `NodesProbe` DTOs/enums, technical contract, in-memory registry/access/default checks, and focused unit tests.
  - [ ] `node:list --doctor` command handoff and renderer integration.
  - [ ] External-reality checks: WireGuard peer reality, platform detection,
    SSH reachability, gateway/app runtime readiness, development TLD, and PHP
    default verification.
- [ ] Port app doctor contracts and checks.
- [ ] Port workspace doctor contracts and checks.
- [ ] Port process doctor contracts and checks.
- [ ] Port proxy route family.
- [ ] Port firewall rule family.
- [ ] Port tool family.
- [ ] Port schedule family.
- [ ] Port enactor/probe/doctor integration pattern with focused tests before
  broader command migration depends on it.

## Testing Infrastructure

- [x] Clean unit/feature test baseline.
- [x] Default `composer test` runs in-memory Pest and excludes E2E.
- [x] Standing infrastructure test lane removed.
- [~] Restore ephemeral E2E.
  - [x] Add Incus backend preflight on beast.
  - [x] Add disposable blank Ubuntu VM lifecycle check.
  - [x] Create a blank snapshot lane for `e2e-provision` tests.
  - [x] Add reusable host installer needed by the base/provisioner topology.
  - [x] Create a stable base image lane for `e2e-feature` tests.
  - [x] Create a prepared superset topology lane from the base image for
    control, gateway, development app, and production app roles.
  - [x] Add first-gateway provisioning E2E lane (`composer test:e2e:provision -- --filter='NodeNewGateway'`)
    that exercises `node:new --role=gateway` on disposable base-provisioned VMs
    (`e2e-provision`).
  - [x] Add control-node onboarding E2E lane (`composer test:e2e:provision -- --filter='GatewayAdd'`)
    that exercises `gateway:add` on disposable base-provisioned VMs
    (`e2e-provision`).
  - [x] Create prepared development app topology lane for `e2e-feature` tests.
  - [x] Create prepared production app topology lane for `e2e-feature` tests.
- [x] Add E2E topology for gateway + control + development app + production
  app nodes.
  - Prepared topology build:
    `composer e2e:prepare-topology -- --force control-gateway-dev-prod`.
  - Docker-backed container-safe feature lane:
    `composer test:e2e`.
  - Docker-backed full topology contract lane:
    `composer test:e2e:topology-contract`.
- [x] Docker-backed feature E2E exists for future app read-command porting.
  - Use `composer test:e2e -- --filter='AppList'` and
    `composer test:e2e -- --filter='AppShow'` once those tests exist.
  - This lane is the default feature E2E lane for app read commands. Keep
    provisioning, WireGuard, SSH trust, host mutation, and destructive flows in
    `e2e-provision`.
- [~] E2E-IMAGE-ARCH-1: stable base image + per-run Orbit provisioner
  - [x] Provisioner script `bin/e2e-provision-node` + apt-deps helper
    `bin/_e2e-deps.sh` (single source of truth shared with the base preparer).
  - [x] `IncusBaseImagePreparer` + `e2e:prepare-base-image` (hidden, with
    `--force` and `--json`).
  - [x] `composer e2e:prepare-base-image` script.
  - [x] `IncusHost::pushBundle` + `IncusHost::provisionInstance` for the
    per-run bundle path.
  - [x] `e2e:prepare-topology --force` builds the source archive once
    (or reuses one via `--branch=<ref>` / `--source-archive=<path>`),
    bundles `bin/install-orbit` + `bin/e2e-provision-node` + the
    `~/.cache/orbit-e2e/composer` cache, and forwards the bundle to the
    builder.
  - [x] `IncusTopologyBuilder` clones every role from
    `orbit-base-ubuntu-26.04` and runs the provisioner before
    authorize/network/ceremony.
  - [x] Drop role-specific `controlImage`/`gatewayImage`/dev/prod aliases
    from `E2EConfig`, `IncusE2EImagePreparationOptions`, and
    `e2e:prepare-incus-images` (now `--role=blank` only).
  - [x] One-shot Beast cleanup
    (`incus image delete orbit-ready-{control,gateway,devapp,prodapp}`).
  - [x] Wall-time check on Beast completed for
    `e2e:prepare-topology -- --force control-gateway-dev-prod`: first
    successful rebuild roughly 3m03s; timed warm rebuild `real 205.71s`.
    Cold target passed; warm target missed by about 26s. Follow-up
    instrumentation landed via Solo todo 298 and measured `real 219.33s`, with
    copy/start plus initial Incus agent readiness as the confirmed bottleneck.
    Because Docker is now the default feature E2E lane, Incus warm-topology
    optimization is treated as future performance work rather than a blocker
    for app read-command porting.
  - [x] Re-run topology contracts on Beast against the new lane
    (`control-gateway-dev-prod` contract passed with 28 assertions).
  - [x] Rework `e2e-provision` tests that previously launched from
    `E2EImage::Control`/`Gateway` (now refused by `IncusProvider::aliasFor`)
    to base + provisioner. Focused provider tests and stale-reference audit
    pass; the full Incus provision-lane run passed via
    `E2E-PROVISION-VERIFY-1` (todo 290).
- [ ] Add provisioning/destructive coverage only in the `e2e-provision` lane
  when working on provisioning, WireGuard, SSH trust, host mutation, or app
  write/destructive commands.

## Next Priorities

1. **Keep app writes blocked until write-safety gates are cleared.**
   - `APP-LIST-1` and `APP-SHOW-1` now have focused Pest and Docker feature
     E2E coverage.
   - `APP-REMOTE-SHELL-1` gives app writes a gateway-owned SSH edge for
     node-side artifact enactment without giving control callers direct SSH
     behavior.
   - `APP-NEW-GATEWAY-LOCAL-1`, `APP-NEW-FWD-1`, the `app:new`
     interactive/human renderer slice, runtime warning handoff foundation,
     PHP-FPM pool rendering/reload, proxy route registry/enactment handoff, and
     process runtime-unit rendering/enactment foundation are implemented. Keep
     `app:register`, `app:root`, `app:remove`, `app:prune`, and
     `app:agent-ide` blocked until `app:new` has real source-creation E2E and
     full registration pipeline convergence coverage.
2. Keep Node destructive/provisioning follow-ups explicit but out of the
   critical path: advanced `node:new` enrollment paths, WireGuard peer teardown,
   and DNS cleanup.
3. `profile` is available as an app verification helper: gateway-mediated target
   resolution/request origin coverage, Toolbar-enriched output, and paired
   Docker feature E2E are implemented. The only remaining profile blocker is
   workspace cwd inference, which waits for workspace schema/models.
