# Orbit Porting Tracker

This file tracks the clean rebuild work still needed to recreate useful Orbit
behavior from `../orbit-old-may`.

## Rules

- Current `docs/` are product authority.
- `../orbit-old-may` is implementation evidence, not product authority.
- Old features should be treated as reference material, not a mandate to copy
  their structure. Before porting behavior, verify whether the clean rebuild can
  implement it more simply, safely, or directly against the current contracts.
- If we decide to keep a feature or command that exists only in
  `../orbit-old-may/docs`, port its documentation into this repo before
  implementing it.
- Legacy command docs must be converted into the current command-doc format
  before the command is built here.
- Every migrated implementation slice must cite the current docs it implements
  and the old code it used as evidence.
- Standing live-node checks on gateway, beast, and mini must stay read-only or
  idempotent.
- Provisioning, destructive, host-mutation, and repair/adoption flows require
  restored ephemeral E2E before they can be treated as fully verified.

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
5. Inspect the old implementation in `../orbit-old-may/app`,
   `../orbit-old-may/config`, `../orbit-old-may/database`, and
   `../orbit-old-may/tests`.
6. Decide whether the old implementation should be ported directly or replaced
   with a simpler clean-rebuild approach that better fits the current docs.
7. Respect the implementation order below unless a verification-helper command
   unlocks better testing for the next slice.
8. Implement the smallest useful vertical slice in the clean repo.
9. Add focused Pest tests that assert the current docs contract, not legacy
   internals.
10. Run the narrow test, then `composer quality-check`.
11. Update this tracker in the same commit as the ported slice.

## Implementation Order

Default migration order is command-contract and capability driven:

1. **Foundation and verification harness.**
   - Keep `composer quality-check` and standing live smoke executable, with
     known red gates tracked here.
   - Until the gateway-to-mini SSH trust decision is resolved, standing live
     smoke is a known red gate: it passes local tests, updates beast, then fails
     when the gateway tries to update mini.
   - Expand the Incus-backed ephemeral E2E harness before provisioning or
     destructive flows depend on it.
   - Use blank Incus VM snapshots for provisioning coverage and ready Incus VM
     snapshots for fast command-porting coverage.
   - Convert docs for the next implementation slice before writing code.
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

- [~] Incus-backed ephemeral E2E harness
  - Current script: `bin/e2e`
  - Current docs: `TESTING.md`
  - Current test script: `composer test:e2e`
  - Bootstrap slice implemented: beast preflight, disposable Ubuntu cloud VM
    launch, ephemeral SSH key injection, SSH readiness check from beast, and VM
    cleanup.
  - Blank lane implemented: `bin/e2e --prepare-blank` builds the reusable
    `orbit-blank-ubuntu-26.04` Incus image; `composer test:e2e` launches from
    that image and verifies SSH through a non-`orbit` bootstrap user.
  - Control-ready lane implemented: `bin/e2e --prepare-control` builds the
    reusable `orbit-ready-control` Incus image from the blank image by
    installing Orbit as a non-`orbit` control user, and `bin/e2e --control`
    launches it and verifies `orbit --version` over SSH.
  - First-gateway provisioning lane implemented: `bin/e2e --node-new-gateway`
    launches a ready control VM and a blank gateway VM, runs
    `orbit node:new --role=gateway` from the control VM, and verifies the
    gateway is provisioned under the steady-state `orbit` user with a working
    Orbit installation.
  - Contract gap: development-app/production-app role provisioning and full
    topology coverage still need ready Incus snapshot lanes.
- [~] Orbit host installer
  - Current implementation: `bin/install-orbit`
  - Current tests: `tests/Feature/Commands/NodeNewCommandTest.php`
  - Bootstrap slice implemented: local control/gateway/app host prerequisite
    installer for Ubuntu and macOS that installs PHP, Composer, Git, Orbit
    source, SQLite database state, migrations, and the `orbit` symlink. Ubuntu
    installs PHP 8.5 by default when the native package is available, with the
    same Ondrej PHP PPA pattern used by old Orbit as a fallback. Ephemeral E2E
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
- [~] `update`
  - Current implementation: `app/Console/Commands/UpdateCommand.php`
  - Current docs: `docs/commands/11_operation/1_update`
  - Current tests: `tests/Feature/Commands/UpdateCommandTest.php`
  - Contract gaps:
    - JSON renderer implementation.
    - Tree-style human progress output.
    - Split operation contract tests mapped by the current docs.
- [~] `update:all`
  - Current implementation: `app/Console/Commands/UpdateAllCommand.php`
  - Current docs: `docs/commands/11_operation/2_update-all`
  - Current tests: `tests/Feature/Commands/UpdateAllCommandTest.php`
  - Contract gaps:
    - caller-role and gateway authorization contract.
    - JSON renderer implementation.
    - tree-style per-installation human progress output.
    - control-node remote update transport metadata and gateway-owned remote
      execution boundary.
    - split operation contract tests mapped by the current docs.
  - Live smoke note: gateway can update beast; gateway-to-mini SSH currently
    fails with `Permission denied (publickey)`.
- [~] `node:list`
  - Current implementation: `app/Console/Commands/NodeListCommand.php`
  - Current docs: `docs/commands/1_node/3_node-list`
  - Current tests: `tests/Feature/Commands/NodeListCommandTest.php`
  - Contract gaps are tracked in the node workstream.
- [~] `node:show`
  - Current implementation: `app/Console/Commands/NodeShowCommand.php`
  - Current docs: `docs/commands/1_node/4_node-show`
  - Current tests: `tests/Feature/Commands/NodeShowCommandTest.php`
  - Bootstrap slice implemented: active registry lookup, human output, JSON
    envelope, not-found error, local-node fallback, and read-only behavior.
  - Contract gaps are tracked in the node workstream.
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
  - Contract gaps are tracked in the node workstream; this is not yet complete
    first-gateway onboarding because WireGuard, gateway API, gateway CA trust,
    real platform detection, and `/api/me` verification are still missing.
- [~] `node:register`
  - Current implementation: `app/Console/Commands/NodeRegisterCommand.php`
  - Current tests: `tests/Feature/Commands/NodeRegisterCommandTest.php`
  - Porting note: bootstrap registry utility for the clean rebuild; not a
    converted product command contract.
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

### Converted Command Docs

- [x] `docs/commands/1_node`
- [x] `docs/commands/2_gateway`
- [x] `docs/commands/3_tool`
- [x] `docs/commands/4_firewall`
- [x] `docs/commands/5_app`
- [x] `docs/commands/6_workspace`
- [x] `docs/commands/7_process`
- [x] `docs/commands/8_proxy`
- [x] `docs/commands/9_schedule`
- [x] `docs/commands/10_deploy`
- [x] `docs/commands/12_cf`
- [x] `docs/commands/13_vpn`
- [x] `docs/commands/14_php`
- [x] `docs/commands/16_dns`

Docs marked converted here mean the command contracts exist in the clean repo.
They do not imply the matching implementation has been ported.

### Converted Legacy Domains

- [x] Proxy routes: `../orbit-old-may/docs/commands/07-proxy-routes`
  - [x] `proxy:list`
  - [x] `proxy:add`
  - [x] `proxy:remove`
  - [x] Legacy `proxy:redirect-add` folded into `proxy:add --redirect=<url>`.
  - [x] Legacy `proxy:redirect-list` folded into `proxy:list --filter=redirect`.
  - [x] Legacy `proxy:redirect-remove` folded into `proxy:remove`.
- [x] Schedules: `../orbit-old-may/docs/commands/08-schedules`
  - [x] `schedule:add`
  - [x] `schedule:list`
  - [x] `schedule:show`
  - [x] `schedule:remove`
  - [x] `schedule:run`
  - [x] `schedule:logs`
- [x] Deployments: `../orbit-old-may/docs/commands/09-deployments`
  - [x] `deploy:step-add`
  - [x] `deploy:step-list`
  - [x] `deploy:step-remove`
  - [x] `deploy:run`
  - [x] `deploy:history`
  - [x] `deploy:log`
- [~] Operations: `../orbit-old-may/docs/commands/10-operations`
  - [x] `doctor`
  - [!] `profile` docs conversion is an early verification-helper candidate
    after node identity and minimal app resolution are available.
  - [x] `update`
  - [x] `update:all`
  - [x] `activity:list`
  - [x] `activity:show`
- [x] Cloudflare: `../orbit-old-may/docs/commands/11-cloudflare`
  - [x] Legacy `cf:zones` renamed to `cf-zone:list`.
  - [x] `cf-dns:list`
  - [x] `cf-dns:add`
  - [x] `cf-dns:remove`
  - [x] `cf-cache:flush`
  - [x] `cf-cache-rule:add`
  - [x] `cf-cache-rule:remove`
  - [x] `cf-ssl:enable`
  - [x] `cf-ssl:disable`
- [x] VPN: `../orbit-old-may/docs/commands/12-vpn`
  - [x] Legacy `vpn-client:list` retained as `vpn-client:list`.
  - [x] Legacy `vpn-client:new` retained as `vpn-client:new`.
  - [x] Legacy `vpn-client:enable` retained as `vpn-client:enable`.
  - [x] Legacy `vpn-client:disable` retained as `vpn-client:disable`.
  - [x] Legacy `vpn-client:remove` retained as `vpn-client:remove`.
  - [x] Legacy `vpn-web-ui:change-password` retained as
        `vpn-web-ui:change-password`.

### Legacy Command Docs Still To Port

- [x] PHP runtime: `../orbit-old-may/docs/commands/13-php`
  - [x] `php:list`
  - [x] `php:use`
  - [x] Tool-specific command family admission policy documented.
- [ ] Agent IDE: `../orbit-old-may/docs/commands/14-agent-ide`
  - [ ] `agent-ide:message`

### Todo Pipeline Hints

These hints are for the Solo pipeline filler. They describe todo sequencing
only; `docs/PORTING.md` workstream statuses remain the authority for completion.

- Do not start new implementation while an active final-review or push recovery
  todo is open.
- After the current `node:new` slice is pushed, fill the next worker-ready queue
  with safe node registry reads first: `node:list`, then `node:show`.
- Keep `node:update` and `node:default` blocked until the read-command slices
  establish stable registry output and helper behavior.
- Keep gateway-family implementation blocked until the node identity and
  first-gateway provisioning prerequisites are clear.
- Do not create app, workspace, process, tool, proxy, Cloudflare, VPN, PHP, or
  agent IDE implementation todos just because their docs exist. Those families
  wait for the node/gateway foundations defined in the implementation order.

## Node Workstream

- [x] Convert node command docs into current format.
- [~] Build minimal node registry read commands.
- [ ] Complete `node:list` contract gaps:
  - [ ] JSON renderer contract.
  - [ ] `--role` and `--environment` filters.
  - [ ] `--doctor` secondary operation.
  - [ ] caller visibility/access-policy behavior.
  - [ ] gateway forwarding.
  - [ ] doctor handoff behavior.
- [ ] Complete `node:show` contract gaps:
  - [ ] caller-role resolution.
  - [ ] access-policy authorization.
  - [ ] gateway forwarding.
  - [ ] interactive prompting.
  - [ ] default development app-node resolution.
  - [ ] real grant metadata.
  - [ ] modeled `environment`, `platform`, and node agent IDE metadata.
- [ ] Reconcile `node:register` with product command contracts or replace it
  with documented `node:new` / `gateway:add` flows.
- [ ] Port `node:update`.
- [ ] Port `node:default`.
- [ ] Port `node:grant`.
- [ ] Port `node:revoke`.
- [ ] Port `node:remove`.
- [ ] Port `node:agent-ide`.
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
  - [x] First-gateway ephemeral E2E lane (`bin/e2e --node-new-gateway`) verifies
    end-to-end provisioning against disposable Incus VMs.
  - [x] First-gateway bootstrap invokes gateway-local internal command over SSH
    to initialize gateway node identity (`is_local=true`) and generate root CA.
  - [x] First-gateway bootstrap captures gateway root CA from remote command
    output and stores it locally for control-node trust.
  - [ ] Interactive input mode.
  - [ ] Gateway-connected forwarding from configured control nodes.
  - [ ] Gateway-local app and control enrollment paths.
  - [ ] Real platform detection.
  - [ ] Full documented JSON success state after WireGuard/API work lands.
- [~] Restore node provisioning support:
  - [~] SSH bootstrap
  - [ ] WireGuard enrollment
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
- [ ] Port `gateway:add`.
- [ ] Port `gateway:trust`.

## Gateway CA Workstream

- [~] Gateway root CA service.
  - [x] `OrbitCaService` generates, reads, and issues from a local gateway-root CA.
  - [x] Truthful CA generation hook in `node:new --role=gateway` first-gateway bootstrap.
    - Gateway-local internal command `orbit:internal:bootstrap-gateway-local` initializes
      the gateway's database identity (`is_local=true`, `role=gateway`, `status=active`)
      and calls `OrbitCaService::ensureRootCa()`.
    - `node:new --role=gateway` invokes this command over SSH after remote Orbit
      installation succeeds, captures the root CA cert, and stores it locally.
    - Focused tests cover CA generation, idempotence, node demotion, invalid-PEM
      rejection, and the full `node:new` bootstrap invocation path.

## DNS Workstream

- [x] Convert DNS command docs into current format.
- [ ] Port `dns:resolve-tld`.
- [ ] Port `dns:list`.

## App Workstream

- [x] Convert app command docs into current format.
- [ ] Port app schema and models needed by documented app commands.
- [ ] Port `app:new`.
- [ ] Port `app:register`.
- [ ] Port `app:list`.
- [ ] Port `app:show`.
- [ ] Port `app:root`.
- [ ] Port `app:remove`.
- [ ] Port `app:prune`.
- [ ] Port `app:agent-ide`.
- [ ] Decide whether legacy app helper commands such as `app:link`,
  `app:secure`, `app:status`, `app:sync`, and scheduler commands should get
  converted docs or stay retired.

## Workspace Workstream

- [x] Convert workspace command docs into current format.
- [ ] Port workspace schema and models.
- [ ] Port workspace lifecycle commands.
- [ ] Port workspace setup and teardown step commands.
- [ ] Port workspace history and log commands.
- [ ] Port workspace progress stream behavior.

## Process Workstream

- [x] Convert process command docs into current format.
- [ ] Port process schema and models.
- [ ] Port process add/edit/remove/list commands.
- [ ] Port process start/stop/restart commands.
- [ ] Port process log and event stream behavior.
- [ ] Port process exit hook support if still part of the product contract.

## Gateway API Client And Transport Workstream

- [ ] Decide the clean-rebuild transport approach before adding packages.
  Legacy Saloon code is reference material and a candidate implementation, not
  required product scope.
- [ ] Port gateway API envelope conventions.
- [ ] Decide whether clean connectors are needed, and if so port or replace
  `GatewayConnector` and `NodeConnector`.
- [ ] Port request correlation header support.
- [ ] Port typed gateway request sender.
- [x] Port WireGuard identity middleware.
- [x] Port `/api/me`.
- [ ] Port node API controllers and typed client requests.
- [ ] Port app API controllers and typed client requests.
- [ ] Port workspace API controllers and typed client requests.
- [ ] Port process API controllers and typed client requests.
- [ ] Port tool/service API controllers and typed client requests after tool
  docs are converted.
- [ ] Port doctor API controllers and typed client requests after doctor docs
  are converted.
- [ ] Port long-running SSE progress primitives.

## State Families And Doctor Workstream

- [ ] Port family inventory from the blueprint into current docs before
  implementation.
- [ ] Port node doctor contracts and checks.
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
- [x] Standing live smoke script exists.
- [!] Standing live smoke is a known red gate until the mini update path is
  fixed or removed from the live-smoke contract.
  - Current behavior: local tests pass, beast updates, then gateway cannot
    update mini because it is not authorized for `nckrtl@10.6.0.8`.
- [~] Restore ephemeral E2E.
  - [x] Add Incus backend preflight on beast.
  - [x] Add disposable blank Ubuntu VM lifecycle smoke.
  - [x] Create a blank snapshot lane for provisioning tests.
  - [x] Add reusable host installer needed by the ready control snapshot.
  - [x] Create a ready control snapshot lane for fast command-porting tests.
  - [x] Add first-gateway provisioning E2E lane (`bin/e2e --node-new-gateway`)
    that exercises `node:new --role=gateway` from a ready control VM against a
    blank gateway VM.
  - [ ] Create ready gateway, development app, and production app snapshot
    lanes for fast command-porting tests.
- [ ] Add E2E topology for gateway + control + development app + production
  app nodes.
- [ ] Add safe read-only standing-node smoke coverage for registry reads.
- [ ] Add provisioning/destructive coverage only against ephemeral nodes.

## Next Priorities

1. Extend `node:new --role=gateway` to finish WireGuard, gateway API, gateway
   CA trust, and `/api/me` verification before treating first-gateway bootstrap
   as contract-complete.
2. Build ready Incus E2E snapshot lanes for fast command-porting tests:
   gateway, development app, and production app VMs.
3. Fix gateway-to-mini SSH trust, or explicitly decide that standing live smoke
   should exclude updating mini.
4. Finish node registry and metadata slices first: `node:update`,
   `node:default`, and the missing `node:list` / `node:show` contract gaps.
5. Convert `profile` docs once its node/app prerequisites are present, then
   port it early as a verification-helper command.
6. Complete the documented `update` and `update:all` implementation gaps once
   the current node access path is stable enough to enforce them.
