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
3. Run `composer docs-lint` when command docs changed.
4. Inspect the old implementation in `../orbit-old-may/app`,
   `../orbit-old-may/config`, `../orbit-old-may/database`, and
   `../orbit-old-may/tests`.
5. Decide whether the old implementation should be ported directly or replaced
   with a simpler clean-rebuild approach that better fits the current docs.
6. Respect the implementation order below unless a verification-helper command
   unlocks better testing for the next slice.
7. Implement the smallest useful vertical slice in the clean repo.
8. Add focused Pest tests that assert the current docs contract, not legacy
   internals.
9. Run the narrow test, then `composer quality-check`.
10. Update this tracker in the same commit as the ported slice.

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
    `orbit-blank-ubuntu-24.04` Incus image; `composer test:e2e` launches from
    that image.
  - Contract gap: role provisioning and gateway/control/development-app/
    production-app topology coverage still need ready Incus snapshot lanes.
- [~] `update`
  - Current implementation: `app/Console/Commands/UpdateCommand.php`
  - Current tests: `tests/Feature/Commands/UpdateCommandTest.php`
  - Contract gap: current operation docs still need to be converted into this
    repo before this can be considered fully ported.
- [~] `update:all`
  - Current implementation: `app/Console/Commands/UpdateAllCommand.php`
  - Current tests: `tests/Feature/Commands/UpdateAllCommandTest.php`
  - Contract gap: current operation docs still need to be converted into this
    repo before this can be considered fully ported.
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

### Already Ported Command Docs

- [x] `docs/commands/1_node`
- [x] `docs/commands/4_app`
- [x] `docs/commands/5_workspace`
- [x] `docs/commands/6_process`

### Legacy Command Docs Still To Port

- [ ] Tools: `../orbit-old-may/docs/commands/02-tools`
  - [ ] `tool:list`
  - [ ] `tool:show`
  - [ ] `tool:install`
  - [ ] `tool:remove`
  - [ ] `tool:start`
  - [ ] `tool:stop`
  - [ ] `tool:restart`
  - [ ] `tool:logs`
  - [ ] `tool:update`
  - [ ] `tool:credentials`
  - [ ] `tool:reload`
  - [ ] `tool:reconfigure`
- [ ] Firewall rules: `../orbit-old-may/docs/commands/03-firewall-rules`
  - [ ] `firewall:list`
  - [ ] `firewall:allow`
  - [ ] `firewall:deny`
  - [ ] `firewall:remove`
- [ ] Proxy routes: `../orbit-old-may/docs/commands/07-proxy-routes`
  - [ ] `proxy:list`
  - [ ] `proxy:add`
  - [ ] `proxy:remove`
  - [ ] `proxy:redirect-add`
  - [ ] `proxy:redirect-list`
  - [ ] `proxy:redirect-remove`
- [ ] Schedules: `../orbit-old-may/docs/commands/08-schedules`
  - [ ] `schedule:add`
  - [ ] `schedule:list`
  - [ ] `schedule:show`
  - [ ] `schedule:remove`
  - [ ] `schedule:run`
  - [ ] `schedule:logs`
- [ ] Deployments: `../orbit-old-may/docs/commands/09-deployments`
  - [ ] `deploy:step-add`
  - [ ] `deploy:step-list`
  - [ ] `deploy:step-remove`
  - [ ] `deploy:run`
  - [ ] `deploy:history`
  - [ ] `deploy:log`
- [ ] Operations: `../orbit-old-may/docs/commands/10-operations`
  - [ ] `doctor`
  - [ ] CA trust command contract
  - [!] `profile` docs conversion is an early verification-helper candidate
    after node identity and minimal app resolution are available.
  - [ ] `update`
  - [ ] `update:all`
  - [ ] `activity:list`
  - [ ] `activity:show`
  - [ ] DNS/TLD resolution commands
  - [ ] DNS list commands
- [ ] Cloudflare: `../orbit-old-may/docs/commands/11-cloudflare`
  - [ ] `cf:zones`
  - [ ] `cf:dns-list`
  - [ ] `cf:dns-add`
  - [ ] `cf:dns-remove`
  - [ ] `cf:cache-flush`
  - [ ] `cf:cache-rule-add`
  - [ ] `cf:cache-rule-remove`
  - [ ] `cf:ssl-enable`
  - [ ] `cf:ssl-disable`
- [ ] VPN: `../orbit-old-may/docs/commands/12-vpn`
  - [ ] `vpn:client-list`
  - [ ] `vpn:client-new`
  - [ ] `vpn:client-enable`
  - [ ] `vpn:client-disable`
  - [ ] `vpn:client-remove`
  - [ ] `vpn:web-ui-change-password`
- [ ] PHP runtime: `../orbit-old-may/docs/commands/13-php`
  - [ ] `php:list`
  - [ ] `php:use`
- [ ] Agent IDE: `../orbit-old-may/docs/commands/14-agent-ide`
  - [ ] `agent-ide:message`

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
- [ ] Port `gateway:add`.
- [ ] Port `node:new`.
- [ ] Restore node provisioning support:
  - [ ] SSH bootstrap
  - [ ] WireGuard enrollment
  - [ ] gateway registry writes
  - [ ] local node role and identity persistence
  - [ ] Orbit API vhost provisioning
  - [ ] Orbit PHP-FPM pool provisioning
  - [ ] gateway-to-node SSH trust model
- [!] Restore ephemeral node E2E before treating provisioning and host-mutation
  flows as fully verified.

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
- [ ] Port WireGuard identity middleware.
- [ ] Port `/api/me`.
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
  - [ ] Create ready control, gateway, development app, and production app
    snapshot lanes for fast command-porting tests.
- [ ] Add E2E topology for gateway + control + development app + production
  app nodes.
- [ ] Add safe read-only standing-node smoke coverage for registry reads.
- [ ] Add provisioning/destructive coverage only against ephemeral nodes.

## Next Priorities

1. Build ready Incus E2E snapshot lanes for fast command-porting tests:
   control, gateway, development app, and production app VMs.
2. Use the blank E2E lane to start node provisioning logic safely before
   treating `gateway:add`, `node:new`, or provisioning-heavy commands as
   complete.
3. Fix gateway-to-mini SSH trust, or explicitly decide that standing live smoke
   should exclude updating mini.
4. Finish node registry and metadata slices first: `node:update`,
   `node:default`, and the missing `node:list` / `node:show` contract gaps.
5. Convert `profile` docs once its node/app prerequisites are present, then
   port it early as a verification-helper command.
6. Convert operations docs for `update` and `update:all` so existing bootstrap
   utilities stop carrying docs debt.
