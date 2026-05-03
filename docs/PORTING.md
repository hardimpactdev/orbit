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
- Standing live infrastructure is not a test lane. Do not use persistent
  gateway, control, or app nodes as verification targets.
- In-memory Pest tests own deterministic command, service, database, renderer,
  and contract coverage.
- Provisioning, destructive, host-mutation, live transport, and repair/adoption
  flows require the `e2e-provisioning` lane before they can be treated as fully
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
   - Keep `composer quality-check` green for in-memory Pest coverage.
   - Expand the Incus-backed ephemeral E2E harness before provisioning or
     destructive flows depend on it.
    - Use blank Incus VM snapshots for the `e2e-provisioning` lane and ready
      Incus VM snapshots for the `e2e-feature` lane.
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

- [x] Incus-backed ephemeral E2E harness (retired legacy `bin/e2e`; now
    artisan commands + Pest E2E suite)
  - Current commands: `php artisan e2e:*`
  - Current docs: `TESTING.md`
  - Current test script: `composer test:e2e`
  - Bootstrap slice implemented: beast preflight, disposable Ubuntu cloud VM
    launch, ephemeral SSH key injection, SSH readiness check from beast, and VM
    cleanup.
  - Blank lane implemented: `php artisan e2e:prepare-incus-images --role=blank --force`
    builds the reusable `orbit-blank-ubuntu-26.04` Incus image; `composer test:e2e`
    launches from that image and verifies SSH through a non-`orbit` bootstrap user.
  - Control-ready lane implemented: `php artisan e2e:prepare-incus-images --role=control --force`
    builds the reusable `orbit-ready-control` Incus image from the blank image by
    installing Orbit as a non-`orbit` control user, and `composer test:e2e:provisioning --filter='control'`
    launches it and verifies `orbit --version` over SSH.
  - First-gateway provisioning lane implemented: `composer test:e2e:provisioning --filter='NodeNewGateway'`
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
  - Live smoke note: gateway-to-beast updates work. The earlier
    gateway-to-mini `Permission denied (publickey)` symptom reflected an
    implementation that targeted the mini control node; under the clarified
    contract, mini is excluded from remote targets entirely.
- [~] `node:list`
  - Current implementation: `app/Console/Commands/NodeListCommand.php`
  - Current docs: `docs/commands/1_node/3_node-list`
  - Current tests:
    - `tests/Feature/Commands/NodeListCommandTest.php` (base contract, filters, validation, read-only)
    - `tests/Feature/Commands/Nodes/NodeListJsonRendererTest.php` (JSON envelope and field contract)
    - `tests/Feature/Commands/Nodes/NodeListHumanRendererTest.php` (human table and prose contract)
  - Contract gaps: caller role and `--doctor` remain tracked in the node workstream.
- [~] `node:show`
  - Current implementation: `app/Console/Commands/NodeShowCommand.php`
  - Current docs: `docs/commands/1_node/4_node-show`
  - Current tests:
    - `tests/Feature/Commands/NodeShowCommandTest.php` (base contract, lookup, fallback, read-only)
    - `tests/Feature/Commands/Nodes/NodeShowJsonRendererTest.php` (JSON envelope and field contract)
    - `tests/Feature/Commands/Nodes/NodeShowHumanRendererTest.php` (human output and prose contract)
  - Bootstrap slice implemented: active registry lookup, human output, JSON
    envelope, not-found error, local-node fallback, and read-only behavior.
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
  - Contract gaps are tracked in the node workstream; this is not yet complete
    first-gateway onboarding because WireGuard, gateway API, gateway CA trust,
    real platform detection, and `/api/me` verification are still missing.
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
  - [x] `vpn-client:list`
  - [x] `vpn-client:new`
  - [x] `vpn-client:enable`
  - [x] `vpn-client:disable`
  - [x] `vpn-client:remove`
  - [x] `vpn-web-ui:change-password`
- [x] `docs/commands/14_php`
  - [x] `php:list`
  - [x] `php:use`
- [x] `docs/commands/15_agent-ide`
  - [x] `agent-ide:message`
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

### Legacy Command Docs Still To Port

All legacy command docs have been converted. See individual family statuses above.

### Todo Pipeline Hints

These hints are for the Solo pipeline filler. They describe todo sequencing
only; `docs/PORTING.md` workstream statuses remain the authority for completion.

#### Pairing Rule (Pest + E2E Per Port)

For every implementation todo the filler creates, also create the paired gate
todos required by the Rules section:

- `E2E-<short-id>` — ephemeral E2E gate todo. Tagged `e2e`, `e2e-gate`,
  `ephemeral`. Promote to `e2e-ready` (NOT `worker-ready`) on
  `SCOUT_REPORT status=READY`. Lane must declare a concrete
  `composer test:e2e:provisioning` invocation, `composer test:e2e:features`
  invocation, `php artisan e2e:*` command, or `lane=none` reason; if that lane
  does not yet exist, create a separate implementer todo to author it before the
  E2E gate becomes dispatchable.

E2E gate todos are dispatched only by the orchestrator's E2E role per
`references/todo-state.md`. Never promote them to `worker-ready` and never
route them to the implementer agent.

#### Sequencing Rules

- Do not start new implementation while an active final-review or push recovery
  todo is open.
- Count only open, unblocked, unlocked `worker-ready` todos as dispatchable
  worker capacity. Blocked `worker-ready` todos are planned inventory, not
  available queue.
- Count `e2e-ready` todos separately from `worker-ready`; both tags consume
  pipeline capacity but dispatch through different orchestrator paths.

#### Current Short Queue (Node Read-Forwarding Chain)

1. Finish and review `NODE-API-READ-1` (todo 230).
2. Then dispatch `NODE-LIST-FWD-1` (todo 234).
3. Then dispatch `NODE-SHOW-FWD-1` (todo 235).
4. Pair each of 234 and 235 with paired E2E gate todos. The E2E gates
   need a `php artisan e2e:prepare-topology` lane authored first (todo 238) — a shared
   lane that spins up control + gateway + dev-app for multiple read-command
   verifications.

Do not create more downstream node-forwarding todos while any of 230, 234, or
235 are still open. If the ready queue is below target during that chain, fill
with independent read-only Pest or harness work only.

#### After Read-Forwarding Chain Verifies (Next 5 Candidates)

1. Authoring `php artisan e2e:prepare-topology` lane (todo 238) so the paired E2E gates
    above become dispatchable.
2. Node doctor contract/docs slice needed for `node:list --doctor`.
3. `node:list --doctor` and doctor handoff implementation (with paired Pest and
   E2E gates).
4. `node:show` real grant metadata / authorization visibility slice (with
   paired Pest and E2E gates).
5. Ready development-app and production-app Incus snapshot lanes (todos 237/239).

#### Gateway Forwarding Chain (Unlocks After 234/235 Pattern Proven)

Once `NODE-LIST-FWD-1` and `NODE-SHOW-FWD-1` are verified, the same
caller-role-branch + typed-request pattern can be applied to the write
commands. Order matters because each adds a new write API endpoint:

1. `NODE-API-UPDATE-1` — gateway-side `PUT /api/nodes/{name}` + `UpdateNodeRequest`.
2. `NODE-UPDATE-FWD-1` — wire `node:update` control-caller forwarding (paired
   E2E only; no smoke — write command).
3. `NODE-API-DEFAULT-1` + `NODE-DEFAULT-FWD-1` — same pattern for `node:default`.
4. `NODE-API-GRANT-1` + `NODE-GRANT-FWD-1` — `node:grant` (paired E2E only).
5. `NODE-API-REVOKE-1` + `NODE-REVOKE-FWD-1` — `node:revoke` (paired E2E only).
6. `NODE-API-REMOVE-1` + `NODE-REMOVE-FWD-1` — `node:remove` (paired E2E only,
   coordinate with WireGuard peer teardown blocker).

Do not create the FWD-* todos until the matching API-* todo is on `main`. Do
not create more than 2 of these chains in flight at once.

#### App Workstream Entry Point (Unlocks After Gateway Forwarding Chain)

App work begins only after the node/gateway foundations are solid. The first
slice candidates, in order:

1. `APP-SCHEMA-1` — port app schema and Eloquent model for the apps table.
2. `APP-API-LIST-1` — gateway-side `GET /api/apps` + `ListAppsRequest`.
3. `APP-LIST-1` — `app:list` command (paired SMOKE + E2E).
4. `APP-API-SHOW-1` — gateway-side `GET /api/apps/{name}` + `ShowAppRequest`.
5. `APP-SHOW-1` — `app:show` command (paired SMOKE + E2E).

Do not create app write commands (`app:new`, `app:remove`, `app:prune`) until
the read pair is verified. Do not create workspace, process, tool, proxy,
Cloudflare, VPN, PHP, or agent IDE implementation todos just because their docs
exist. Those families wait for the node/gateway/app foundations.

#### Hard Blocks

- Keep gateway-family implementation blocked until the node identity and
  first-gateway provisioning prerequisites are clear.
- Keep workspace, process, and downstream families blocked until the app
  workstream entry point is verified.

## Node Workstream

- [x] Convert node command docs into current format.
- [~] Build minimal node registry read commands.
- [~] Complete `node:list` contract gaps:
  - [x] JSON renderer contract.
  - [x] Human renderer contract.
  - [x] `--role` and `--environment` filters.
  - [ ] `--doctor` secondary operation.
  - [ ] caller visibility/access-policy behavior.
  - [ ] gateway forwarding.
  - [ ] doctor handoff behavior.
- [~] Complete `node:show` contract gaps:
  - [x] modeled `environment`, `platform`, and node agent IDE metadata.
  - [x] JSON renderer contract (envelope shape, field contract, all error codes and metadata).
  - [x] Human renderer contract (field order, grants section, failure prose).
  - [ ] caller-role resolution.
  - [ ] access-policy authorization.
  - [ ] gateway forwarding.
  - [ ] interactive prompting.
  - [ ] default development app-node resolution.
  - [ ] real grant metadata.
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
  - Bootstrap slice implemented: gateway-local update with progress tree, field validation, role-incompatibility checks, and split contract tests.
  - Contract gaps:
    - control-node forwarding to gateway (requires GatewayClient, GATEWAY-1/202).
    - interactive input mode (prompting for name and field selection).
    - artifact re-enactment after intent update.
    - `NodeUpdateOnControlNodeContractTest.php` (blocked by gateway forwarding).
- [~] Port `node:default`.
  - Current implementation: `app/Console/Commands/NodeDefaultCommand.php`
  - Current docs: `docs/commands/1_node/9_node-default`
  - Current tests:
    - `tests/Feature/Commands/NodeDefaultCommandTest.php` (flat base contract)
    - `tests/Feature/Commands/Nodes/NodeDefaultCommandTest.php` (split command contract)
    - `tests/Feature/Commands/Nodes/NodeDefaultHumanRendererTest.php` (human renderer contract)
    - `tests/Feature/Commands/Nodes/NodeDefaultJsonRendererTest.php` (JSON renderer contract)
  - Bootstrap slice implemented: local read/show/set/clear sub-actions, human progress tree, JSON envelope shape, caller role rejection, split contract tests.
  - Contract gaps:
    - Gateway forwarding for set/choose (requires GatewayClient and gateway API; currently queries local DB only).
    - Real authorization check against gateway-visible nodes (`authorization_failed` is a stub bootstrap gap).
    - Interactive choose path requires real gateway node list (`gateway_unavailable` is a stub bootstrap gap).
    - `NodeDefaultOnControlNodeContractTest.php` blocked by gateway forwarding.
- [~] Port `node:grant`.
  - Current implementation: `app/Console/Commands/NodeGrantCommand.php`
  - Current docs: `docs/commands/1_node/5_node-grant`
  - Current tests:
    - `tests/Feature/Commands/Nodes/NodeGrantCommandTest.php` (command contract)
    - `tests/Feature/Commands/Nodes/NodeGrantHumanRendererTest.php` (human renderer contract)
    - `tests/Feature/Commands/Nodes/NodeGrantJsonRendererTest.php` (JSON renderer contract)
  - Bootstrap slice implemented: gateway-local grant creation, idempotence, node-not-found validation, self-grant policy enforcement, caller role rejection, human and JSON renderer contracts, split contract tests.
  - Contract gaps:
    - control-caller gateway forwarding (requires typed gateway request sender / GatewayClient).
    - `authorization_failed` runtime check (requires gateway API auth; currently a stub bootstrap gap tested via reflection).
    - `NodeGrantOnControlNodeContractTest.php` blocked by gateway forwarding.
- [~] Port `node:revoke`.
  - Current implementation: `app/Console/Commands/NodeRevokeCommand.php`
  - Current tests:
    - `tests/Feature/Commands/Nodes/NodeRevokeCommandTest.php` (command contract)
    - `tests/Feature/Commands/Nodes/NodeRevokeHumanRendererTest.php` (human renderer contract)
    - `tests/Feature/Commands/Nodes/NodeRevokeJsonRendererTest.php` (JSON renderer contract)
    - `tests/Feature/Commands/Nodes/NodeRevokeInteractiveInputModeTest.php` (interactive input mode contract)
  - Bootstrap slice implemented: gateway-local grant revocation, idempotence, node-not-found validation, self-lockout detection, destructive consent (`--force`, interactive confirmation), caller role rejection, human progress tree and JSON renderer contracts, split contract tests.
  - Contract gaps:
    - control-caller gateway forwarding (requires typed gateway request sender / GatewayClient).
    - `authorization_failed` runtime check (requires gateway API auth; currently a stub bootstrap gap tested via reflection).
    - `NodeRevokeOnControlNodeContractTest.php` blocked by gateway forwarding.
    - Interactive prompt testing in PHPUnit/Pest is limited by non-TTY environment; confirmation decline and prompt abort behavior are covered by command logic but not fully exercised via automated prompts.
- [~] Port `node:remove`.
  - Files:
    - `app/Console/Commands/NodeRemoveCommand.php` (gateway-local bootstrap slice)
    - `tests/Feature/Commands/Nodes/NodeRemoveCommandTest.php` (command contract)
    - `tests/Feature/Commands/Nodes/NodeRemoveHumanRendererTest.php` (human renderer contract)
    - `tests/Feature/Commands/Nodes/NodeRemoveJsonRendererTest.php` (JSON renderer contract)
    - `tests/Feature/Commands/Nodes/NodeRemoveInteractiveInputModeTest.php` (interactive input mode contract)
  - Bootstrap slice implemented: gateway-local node removal, grant cascade (consumer and serving directions), node-not-found validation (NOT idempotent), gateway-node rejection, destructive consent (`--force`, interactive confirmation), caller role rejection, human progress tree and JSON renderer contracts, split contract tests.
  - Contract gaps:
    - control-caller gateway forwarding (requires typed gateway request sender / GatewayClient).
    - `authorization_failed` runtime check (requires gateway API auth; currently a stub bootstrap gap tested via reflection).
    - `NodeRemoveOnControlNodeContractTest.php` blocked by gateway forwarding.
    - WireGuard peer teardown (no WireGuard peer management infrastructure in clean repo yet; `wireguard_peer_removed: false` in JSON response).
    - DNS mapping cleanup for dev-app nodes (requires gateway API DNS support).
    - Interactive prompt testing in PHPUnit/Pest is limited by non-TTY environment; confirmation decline and prompt abort behavior are covered by command logic but not fully exercised via automated prompts.
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
  - [x] First-gateway ephemeral E2E lane (`composer test:e2e:provisioning --filter='NodeNewGateway'`) verifies
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
      installation succeeds, captures the root CA cert, and stores it locally.
    - Focused tests cover CA generation, idempotence, node demotion, invalid-PEM
      rejection, and the full `node:new` bootstrap invocation path.

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
    - Ephemeral E2E lane (`composer test:e2e:provisioning --filter='DnsResolveTld'`) is tracked as todo 245 (DNS-LANE-RESOLVE-TLD-1); deferred until E2E harness lane is authored.
    - Linux backend support is intentionally deferred; only macOS dnsmasq backend is implemented.
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

### GATEWAY-API-0 Decision: Gateway API Client Transport

**Status:** Decided — thin `GatewayClient` wrapper over Laravel `Http` facade.

**Chosen approach:** (b) a dedicated thin `GatewayClient` wrapper/service.

**Rationale:**

1. **Current clean-repo evidence:** `gateway:add` and `FetchGatewayRootCa` already
   use Laravel's `Http` facade with `withOptions(['verify' => $pemPath])`. The
   clean repo has no Saloon dependency and no typed request classes. The existing
   pattern works for the two gateway API calls currently implemented (`/api/ca/root`
   and `/api/me`).

2. **Legacy Saloon evidence:** The old repo used `saloonphp/saloon` v4 with
   `GatewayConnector`, `NodeConnector`, ~100 typed request classes under
   `app/Http/Saloon/Requests/`, and `GatewayRequestSender`/`NodeRequestSender`
   envelope parsers. This was a large abstraction surface. `NodeRequestSender` was
   already deprecated in the old repo in favor of `GatewayRequestSender`.
   `NodeApiClient` in the old repo actually used the Laravel `Http` facade
   directly (not Saloon) for node-to-gateway calls.

3. **Product authority:** `docs/PORTING.md` explicitly states "Legacy Saloon code
   is reference material and a candidate implementation, not required product
   scope." The blueprint treats the typed API as transport only; commands are the
   stable contract. The clean-rebuild constraint is to avoid reintroducing broad
   legacy abstractions until the clean codebase has a concrete need for them.

4. **Why not inline `Http::withOptions` per call:** The CA path, base URL,
   correlation header, and `allow_redirects` config would be repeated in every
   command. A thin wrapper centralizes this without adding an external package.

5. **Why not Saloon:** Saloon adds an external dependency and a taxonomy of typed
   request classes that the current clean repo does not need. The old Saloon
   request classes mapped one-to-one to gateway API endpoints; the clean rebuild
   can achieve the same endpoint coverage with far less code by using Laravel's
   built-in HTTP client plus a thin wrapper that pre-configures the gateway
   connection. If future needs (e.g., advanced mocking, plugin pipelines, or
   non-Laravel consumers) justify Saloon, it can be adopted later without
   blocking current work.

6. **Why not a Laravel HTTP macro:** Macros are global and affect all `Http`
   calls, making testing and isolation harder. A service-class wrapper is
   explicit and injectable.

**Recommended abstraction:**

A `GatewayClient` service class (or similar) that:
- Resolves the gateway base URL from `LocalGatewaySettings::current()`.
- Configures `verify` with the stored CA PEM path.
- Sets `allow_redirects => false` and `acceptJson()`.
- Adds the `X-Orbit-Request-Id` correlation header when available.
- Returns a Laravel `PendingRequest` so callers chain methods naturally
  (e.g., `GatewayClient::withGateway()->get('/api/me')`).
- Does NOT yet implement envelope parsing; envelope handling should be added
  incrementally as typed gateway API calls are ported (see downstream workstream
  items).

**Downstream impact:**
- The wrapper should be created before porting the first typed gateway API call
  (e.g., `node:list` with gateway forwarding or `app:list`).
- The old `GatewayRequestSender` envelope parser is reference material for how
  to parse the `success`/`error` discriminated envelope, but should be ported
  as a focused helper only when the first typed caller needs it.

### Remaining Workstream Items

- [x] Decide the clean-rebuild transport approach before adding packages.
- [x] Create thin `GatewayClient` wrapper (pre-requisite for typed calls).
- [x] Port gateway API envelope conventions.
- [x] Port request correlation header support.
- [x] Port typed gateway request sender.
- [x] Port WireGuard identity middleware.
- [x] Port `/api/me`.
- [~] Port node API controllers and typed client requests.
  - Bootstrap slice implemented: `GET /api/nodes` and `GET /api/nodes/{node}` endpoints
    with `NodeListController` and `NodeShowController`, `WireGuardIdentity` middleware
    enforcement, `GatewayEnvelope` JSON response shape, and typed request classes
    (`ListNodesRequest`, `ShowNodeRequest`) using the `GatewayRequestSender` convention.
  - Tests: `NodeListControllerTest`, `NodeShowControllerTest`,
    `ListNodesRequestTest`, `ShowNodeRequestTest`.
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
- [x] Default `composer test` runs in-memory Pest and excludes E2E.
- [x] Standing infrastructure test lane removed.
- [~] Restore ephemeral E2E.
  - [x] Add Incus backend preflight on beast.
  - [x] Add disposable blank Ubuntu VM lifecycle smoke.
  - [x] Create a blank snapshot lane for `e2e-provisioning` tests.
  - [x] Add reusable host installer needed by the ready control snapshot.
  - [x] Create a ready control snapshot lane for `e2e-feature` tests.
  - [x] Create a ready gateway snapshot lane (`php artisan e2e:prepare-incus-images --role=gateway --force`)
    that builds a reusable `orbit-ready-gateway` image with bootstrapped
    gateway identity and root CA.
  - [x] Add first-gateway provisioning E2E lane (`composer test:e2e:provisioning --filter='NodeNewGateway'`)
    that exercises `node:new --role=gateway` from a ready control VM against a
    blank gateway VM (`e2e-provisioning`).
  - [x] Add control-node onboarding E2E lane (`composer test:e2e:provisioning --filter='GatewayAdd'`) that
    exercises `gateway:add` from a ready control VM against a ready gateway VM
    (`e2e-provisioning`).
  - [~] Create ready development app snapshot lane for `e2e-feature` tests.
  - [ ] Create ready production app snapshot lane for `e2e-feature` tests.
- [ ] Add E2E topology for gateway + control + development app + production
  app nodes.
- [ ] Add provisioning/destructive coverage only in the `e2e-provisioning` lane.

## Next Priorities

1. Extend `node:new --role=gateway` to finish WireGuard, gateway API, gateway
   CA trust, and `/api/me` verification before treating first-gateway bootstrap
   as contract-complete.
2. Build ready Incus E2E snapshot lanes for fast command-porting tests:
   gateway, development app, and production app VMs.
3. Finish node registry and metadata slices first: `node:update`,
   `node:default`, and the missing `node:list` / `node:show` contract gaps.
4. Convert `profile` docs once its node/app prerequisites are present, then
   port it early as a verification-helper command.
5. Complete the documented `update` and `update:all` implementation gaps once
   the current node access path is stable enough to enforce them.
