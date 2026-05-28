# CLI-First Command Surface Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Once Solo todos exist, the Solo tracker is the execution state. The checkboxes below are the source checklist, not a live status ledger.

**Goal:** Make `apps/cli/orbit` the real installed Orbit CLI while keeping `apps/gateway` as the fleet authority and using hidden token-gated CLI executor commands only for gateway-dispatched node work that belongs in the documented local-executor lane.

**Architecture:** The installed command remains `/usr/local/bin/orbit -> ~/orbit/bin/orbit -> apps/cli/orbit`. The launcher wrapper stays because it owns `ORBIT_REPO`, `ORBIT_HOST_CWD`, executor secret exports, node identity exports, and host path normalization. Public commands are implemented by the CLI app. Most public commands call typed gateway API endpoints and render human or JSON output. Explicit exceptions are local-only commands, which mutate caller-local configuration, and bootstrap commands, which run before a gateway API exists. The CLI stores per-node gateway endpoint, trust material, and local defaults in `~/.config/orbit/config.json`; a CLI database is intentionally out of scope. Gateway API identity still comes from Orbit's WireGuard/proxy identity model, not a persisted bearer identity in CLI config. Hidden `internal:*` commands are CLI commands too, but they are not public product commands, are hidden from normal lists, and require gateway-issued operation tokens. CLI command classes are adapters, not workflow owners: they resolve input, call typed gateway transports or node-local action/service classes, and render output. Laravel Zero remains CLI-only; any future node API is a separate runtime/transport that invokes the same action/service contracts and never runs public CLI commands.

**Tech Stack:** PHP 8.5, Laravel Zero 12 CLI app, Laravel 13 gateway app, `packages/core` for shared protocol contracts, Pest 4, Laravel Pint, Larastan/PHPStan, prepared Docker/Incus E2E lanes.

---

## Overview

This is a migration plan, not a single refactor. The current implementation has three coupled surfaces:

- `bin/orbit` dispatches gateway nodes to `apps/gateway/artisan`.
- `apps/cli/orbit` forwards unported public commands to `apps/gateway/artisan`.
- `apps/gateway` owns both public command classes and gateway execution logic.

The target state has three explicit command implementation lanes:

- Public gateway-backed commands live in `apps/cli/app/Commands` and call the gateway API.
- Public local/bootstrap commands live in `apps/cli/app/Commands` and are documented as exceptions to the normal gateway-backed path.
- Hidden node task commands live in `apps/cli/app/Commands/Internal` and require gateway-issued operation tokens.

`apps/gateway/artisan` remains for gateway runtime, scheduler, migrations, E2E support, explicit internal maintenance, and developer operations through paths such as `bin/orbit-gateway-artisan`. It is not the public `orbit` command target.

During migration, a temporary compatibility bridge may exist inside `apps/cli/orbit` for explicitly allow-listed unported public commands. That bridge is a transitional safety mechanism, not the target architecture. It must never forward hidden `internal:*` commands, gateway runtime commands, Laravel framework/dev commands, or unknown commands.

## Complexity

Files: 50+
Modules: product docs, launcher, CLI app, core protocol package, gateway API, operation records, RemoteLocalExecutor, command tests, E2E topology tests
Risk: High

## Non-Negotiable Boundaries

- Public gateway-backed CLI commands never mutate gateway or node state directly.
- Public local-only CLI commands may mutate caller-local state only when the command contract documents that lane.
- Public bootstrap CLI commands may use pre-gateway SSH/bootstrap logic only when no gateway API exists yet or the target has not reached the Orbit baseline.
- The gateway never SSHes to a node to run a public user command.
- Gateway-to-node CLI reuse only happens through hidden `internal:*` commands.
- Hidden `internal:*` commands are only for the documented `RemoteLocalExecutor` lane. They are not a blanket replacement for `RemoteHostExecutor`, `RemoteOrbitRuntimeExecutor`, package installation, Caddy artifact mutation, firewall mutation, or other host substrate work unless product docs are changed first.
- Every hidden executor command validates an operation token before command-specific validation, reads, or side effects.
- Operation tokens are verified at command entry. Long-running internal commands are not required to refresh or re-validate tokens mid-execution unless a later product decision changes that contract.
- CLI command classes must remain adapter-thin. They may parse input, resolve caller-local config, authorize/validate at the command boundary, invoke a gateway transport or node-local action/service, and render output. They must not own product workflow orchestration, persistence policy, retry policy, host mutation, or multi-step node-local business logic.
- Hidden `internal:*` commands that perform non-trivial node-local work must delegate to typed action/service classes and return typed results. Future node API runtimes must reuse those action/service contracts rather than shelling out to public CLI commands.
- Gateway-owned queueing, operation state, redaction, and future client broadcasting stay in `apps/gateway`. The CLI and hidden node-local commands may emit structured progress observations, but they do not decide gateway-side writes or broadcast directly to operator clients.
- Long-running internal executor commands, when a command contract requires node-originated progress, use a framed progress stream over the gateway-owned transport. Internal commands do not call back to the gateway only to report progress.
- Do not split every visible progress label into a separate hidden mini-command. Create one internal command per cohesive node-local operation; split commands only at real retry, reuse, permission, isolation, or transaction boundaries.
- `packages/core` contains protocol contracts, DTOs, enums, progress/event helpers, token helpers, and envelope helpers only.
- `packages/core` must not depend on Eloquent models, gateway controllers, `RemoteShell`, command classes, terminal renderers, or product workflow services.
- CLI-local state lives in JSON at `~/.config/orbit/config.json` by default, with an `ORBIT_CONFIG_PATH` override for tests and special deployments. The file must be written atomically with owner-only permissions and must not require SQLite or another CLI database.
- CLI config stores gateway endpoint, gateway WireGuard address, gateway CA trust material, timeouts, profile, and local defaults. It must not persist a bearer-style gateway identity as product state. Any temporary `ORBIT_GATEWAY_IDENTITY` compatibility remains test/development-only until gateway auth is rewritten to the WireGuard/proxy model.
- Remote node task output may report observations and typed results. It must not ask the gateway to run arbitrary follow-up work. The gateway operation handler decides gateway-side writes and follow-up jobs.
- JSON API envelope changes are breaking changes for direct API consumers. They require explicit docs, tests, and a compatibility/versioning decision before code relies on the new shape.

## AGENTS.md Prerequisite Gates

These gates exist because `AGENTS.md` makes specific rules non-negotiable for this repo. They are checked before the listed phases start and are part of every PR description in the relevant phase.

- **G1 Testing README required before E2E work.** Before any phase that adds or modifies E2E lanes (Phase 4 retarget and verification gate; Phase 7 OperationRun tests; Phase 9 stream tests; Phase 12 full E2E run), the PR author confirms in writing they have read `apps/docs/content/testing/README.md` and references the relevant lane (prepared-topology feature tests, provisioning tests, host pools, cache strategy, performance baselines).
- **G2 command-designer skill for command-contract changes.** Before the Phase 1 command classification matrix lands, and before any command-contract page is added or rewritten in subsequent phases, the author invokes the `.agents/skills/command-designer` skill (per `AGENTS.md:55-57`) and notes the skill-run output in the PR description. This applies to bucketing public vs hidden commands, naming `internal:*` commands, and every per-family port in Phase 6/8.
- **G3 Live-node verification owner + observation window for Phase 4.** Per `AGENTS.md:54` and the documented absence of a standing live-node test lane, the Phase 4 launcher switch is reviewed against running live nodes by a named verification owner before the PR is merged. After merge, the launcher behavior is observed on at least one live gateway and one live workload node for 72 hours; the observer logs any regression in the Phase 4 PR thread. If no live node is available, the merge is blocked. `composer test:e2e:docker` and `composer test:e2e:provision` are necessary but not sufficient on their own.
- **G4 Live-node verification for `node:default` cutover (Phase 8).** The `node:default` storage migration (D11) is operator-visible and removes a gateway API. The Phase 8 PR that deletes `/api/nodes/default` and `LocalNodeDefault` carries the same named verification owner + 72-hour post-merge observation requirement as G3, exercised on at least one live operator workstation and one live gateway host. The observation log goes into the PR thread.

## Decisions Recorded

These decisions resolve the open questions that previously blocked Phase 2 and Phase 4. They are part of the plan and are not deferred. If any of them must change, change the plan first.

- **D1 Gateway-host self-client.** Gateway hosts call their own API as HTTPS over WireGuard at the gateway's own WireGuard address, using the gateway CA PEM written under `~/.config/orbit/gateways/default/`. There is no privileged local loopback bypass. The first-gateway bootstrap brings WireGuard up before any gateway-host CLI call is required.
- **D2 Gateway API identity.** Production gateway API identity comes only from WireGuard peer resolution (`X-Orbit-WireGuard-Ip` via orbit-caddy, or direct peer address when caddy is not in the path). The CLI never spoofs that header. `ORBIT_GATEWAY_IDENTITY` is dead config that exists only because tests still call `Http::withToken(...)`. Phase 3 deletes the env-driven bearer path from `GatewayApiClient`, ports the affected tests to the WireGuard header path gated by `orbit.e2e_trust_wireguard_header` / `orbit.trust_wireguard_proxy_header`, and removes the `gateway.identity` config key.
- **D3 Executor secret source on every node.** Two distinct secrets live in `${ORBIT_REPO}/apps/gateway/.env`: `ORBIT_OPERATION_TOKEN_SECRET` is the **gateway-side mint secret** and never needs to be in any non-gateway process; `ORBIT_EXECUTOR_SECRET` is the **node-local verifier secret** used by `OperationTokenGuard` to verify minted tokens. `ORBIT_NODE_IDENTITY` is the per-node identity that internal commands self-identify with. The launcher only exports `ORBIT_EXECUTOR_SECRET` and `ORBIT_NODE_IDENTITY` when the matched command name is in the launcher's `internal:*` allow-list; it does not export `ORBIT_OPERATION_TOKEN_SECRET` to any node-local CLI process. Public commands run without any of those three vars in their environment, even on gateway hosts. A non-`.env` per-node secret file is explicitly out of scope here.
- **D4 Envelope compatibility.** Phase 2 lands a coordinated breaking-change window. There is no `/v2` API path and no double-emit shim. The cutover requires: (a) the canonical `success` / `error` shape documented under `apps/docs/content/architecture.md`; (b) a release-note entry naming Solo agents, Codex scripts, and external script consumers; (c) every gateway controller already auditied against the canonical shape before any CLI consumer of `--json` is changed. Direct API users get a single release boundary, dated in the release notes.
- **D5 Operation run identity vs token JTI.** `operation_runs.id` is the per-attempt UUID. Add `operation_id` as a separate UUID column that groups attempts for the same logical operation. The operation token contract does not gain a separate JTI field; an operation that re-mints a token reuses the prior `operation_id` and gets a fresh `operation_runs.id`. Re-mint within the same lane is allowed and tested.
- **D6 Compatibility-bridge boundary.** The compatibility bridge inside `apps/cli/orbit` exists only as a named migration mechanism between Phase 4 and Phase 11. Its scope is bounded by the Phase 1 command classification matrix and the committed bridge allow-list: every allow-list entry must have an owner family and a removal phase. No new command family may be added to the allow-list once the Phase 1 matrix is committed. Phase 11 removes the bridge entries that have been retired. Any remaining families must move to a follow-up plan with a fresh bridge contract; the existing bridge is never silently extended.
- **D7 Operation token TTL semantics.** Operation tokens are validated at command entry only. Once an internal command has started, the token is informational and is not re-checked. Long-running internal commands raise their per-command TTL through the gateway operation contract; they do not refresh tokens mid-flight. This matches the architecture default (120 s) and the non-negotiable boundary above.
- **D8 Owner user model.** `~/.config/orbit/config.json` is owned by the invoking OS user. On nodes that means the `orbit` system user; on developer Macs that means the operator's user. Multi-user nodes sharing one `orbit` install are out of scope. "Fix permissions silently when owner matches" means the owner of the running process; any other owner is refused as `config_insecure_permissions`.
- **D9 Operator-visible `operation:*` commands.** Operation runs are internal telemetry for this plan. No `operation:*` read commands are added on this iteration. A follow-up plan may add them after the schema and retention model have stabilized.
- **D10 `--json` over streamed commands.** Streamed commands in `--json` mode consume the gateway SSE stream and emit only the final `complete` or `error` frame as a single JSON object. No parallel non-SSE endpoint is added. The gateway side keeps emitting existing `: heartbeat` SSE comment frames; the CLI decoder drops them. No gateway change to remove keepalives.
- **D11 `node:default` storage migration.** `node:default` becomes per-operator-host CLI-local JSON config. Default-node resolution is **client-side only**: every CLI command that takes a node target resolves it from `OrbitConfigStore` and passes the resolved node identifier as `--node=X` (or the equivalent API payload field) to the gateway. The gateway never reads a default-node value at request time. The gateway side retires the public `/api/nodes/default` routes (`Route::*('/nodes/default', ...)` in `apps/gateway/routes/api.php:212-214`), removes the `LocalNodeDefault` model and the `local_node_defaults` table, and removes every server-side consumer (none can call into a per-OS-user store from inside an API request). The Phase 3 importer drains the most recent `LocalNodeDefault` row into `defaults.node` on the operator host as a one-time migration. The operator-visible behavior change (default is now per-operator-host, not per-gateway) is documented in `apps/docs/content/domains/1_node/9_node-default/technical/1_node-default.md` in Phase 1. `LocalGatewaySettings` is **distinct** from `LocalNodeDefault`; it is the gateway-runtime's own self-trust material and is handled under D17, not retired here.

  **Type-(c) policy for gateway-runtime callers (chosen now, applied in Phase 8):** scheduler and gateway-runtime code paths (D8 OS-user mismatch — they cannot read `OrbitConfigStore`) require an explicit node target on every call. There is no fallback default for these callers. Specifically: (a) the scheduler resolves the node when the command was registered and stores the resolved node identifier on the dispatched operation row (or scheduled-job payload), so the runtime never needs to "guess" a default; (b) gateway-runtime CLI commands invoked from container shells or maintenance scripts require `--node=X`; the command fails with the distinct code `node_target_required` if no node is supplied and no operation row already carries one; (c) interactive operator prompts are **not** an acceptable fallback for type-(c) callers because they break non-interactive scheduler paths. Type-(c) consumers from the current grep include `DoctorCommand` (scheduler path), `NodesProbe`, `NodeSecurityPostureProbe`, `PhpRuntimeManager`, and `ProcessEventNotifierRenderer`; Phase 8 enumerates them exhaustively with the per-caller resolution path. The Phase 3 caller audit step writes the full caller mapping table.
- **D12 Envelope wrapping policy.** Gateway-backed public CLI commands pass the gateway-rendered `success` body through verbatim. The CLI's `renderSuccess` helper detects an inbound envelope and unwraps `success.data` and `success.meta` into the helper's `data`/`meta` arguments rather than nesting. No re-envelope occurs; no `success.success` is ever emitted. Local-only and bootstrap commands construct the envelope themselves through `renderSuccess` / `renderFailure`. Tests assert no double-wrap on every ported command family.
- **D13 OrbitConfigStore env precedence mechanism.** The env-then-JSON precedence chain lives **inside the `GatewayApiServiceProvider` binding closure**, not inside `apps/cli/config/orbit.php`. `apps/cli/config/orbit.php` stays env-only and does not call `new OrbitConfigStore()` at config-load time (Laravel loads `config/*.php` before service providers register, so a config-time read of the store would be fragile and would interact badly with `config:cache`). `GatewayApiClient` is constructed from the provider closure; the closure asks `OrbitConfigStore` for the active gateway entry, then overlays env values when present. Document the `config:cache` interaction: cached configs do not reflect `~/.config/orbit/config.json` edits until the next command run rebuilds the closure binding; this is OK because the provider closure is lazy.
- **D14 Operation run ↔ activity log relationship.** `operation_runs` is the parent table. The target activity table is the Spatie ActivityLog table created by `apps/gateway/database/migrations/2026_05_05_180000_create_activity_log_table.php`. Its real name is `config('activitylog.table_name')` (defaults to `activity_log`); never refer to it as `activities`. Its existing columns are `description`, `subject_type`, `subject_id`, `causer_type`, `causer_id`, `properties` (JSON), `event`, `batch_uuid`, and the standard timestamps. There is no `command_line` column and no `metadata` column on this table; the redacted command line lives inside `properties` per the existing local-executor activity contract. The new schema work for this plan: add a nullable `operation_run_id` foreign key column to the activity table via a fresh migration; activity rows under the `local_executor.dispatching` and `local_executor.completed` channels write that FK pointing back at `operation_runs.id`. The `dispatch_activity_id` / `completion_activity_id` columns on `operation_runs` are removed from this plan; the recorder writes the activity row after the operation_runs row exists. Redaction is tested in both directions: for a given `operation_id`, the `properties` JSON on every linked activity row contains no raw `--operation-token` substrings or other redacted secret values.
- **D15 Dev-Mac WireGuard requirement.** Operator-Mac CLI calls to a remote gateway require either (a) the Mac is a WireGuard peer of that gateway, or (b) the Mac calls a gateway that has `orbit.trust_wireguard_proxy_header` set so an upstream proxy can forward `X-Orbit-WireGuard-Ip`. The plan does not add a third path. `gateway:add` is the first command an operator runs; it does not require WireGuard to be up, because it uses an out-of-band CA fingerprint and Orbit's gateway public bootstrap endpoints (`/api/ca/root`) to negotiate trust. After `gateway:add`, the operator must bring WireGuard up before the next CLI call. If WireGuard is required but not configured, the CLI fails with the distinct code `gateway_unreachable_wireguard`, not the generic `gateway_unavailable`.
- **D16 First-gateway bootstrap sequence.** `node:new --template=gateway` (BootstrapGatewayCommand) is allowed to write `~/.config/orbit/config.json` without first reaching the gateway, because the WireGuard endpoint and CA material are computed locally during gateway provisioning. The exact order is:
  1. Operator runs `node:new --template=gateway` on a fresh host.
  2. BootstrapGatewayCommand provisions the gateway runtime, mints the gateway WireGuard key pair, writes CA material to `~/.config/orbit/gateways/default/`, and writes the full gateway entry (url, wireguard_ip, ca_pem_path, ca_sha256, self_mode) to `~/.config/orbit/config.json`.
  3. The gateway runtime is started.
  4. Subsequent CLI calls on the same host route through the WireGuard HTTPS path that the JSON config now points at.
- **D17 Gateway-runtime self-trust material location.** The gateway-runtime container reads its own URL, WireGuard IP, and CA material from its existing `LocalGatewaySettings` row in `apps/gateway/database/database.sqlite`. It does not read `~/.config/orbit/config.json`, because the runtime user is not the operator user (per D8). `GatewayConnector` and `GatewayStreamTransport` keep their current `LocalGatewaySettings::current()` reads; `LocalGatewaySettings` is **not** removed by D11. The CLI on the same gateway host (running as the operator user) writes to `~/.config/orbit/config.json` per D16; the runtime keeps writing to its own SQLite during first-gateway bootstrap. Phase 3 documents the dual writers explicitly and adds a regression test that the two stores stay in sync after `gateway:add` / `gateway:trust`.
- **D18 Thin command and action boundary.** Public CLI commands, local-only/bootstrap commands, and hidden internal commands are transport/UI adapters. Product behavior and node-local business logic live in gateway services or CLI action/service classes with typed inputs and typed results. This is required so Laravel Zero can stay a true CLI while a future node API, if added, can be implemented as a separate runtime/transport that invokes the same action/service contracts. The future API must not run public CLI commands as its business layer.
- **D19 Internal progress-frame contract.** Completion-style `internal:* --json` remains the default for short work: one typed JSON result is written after token validation and action execution. Long-running internal commands that need node-originated progress must use a separate framed stream contract with `tree`, `step`, `complete`, and `error` frames. The stream is one protocol for the whole command; arbitrary console output must not be mixed with final JSON. The gateway consumes the SSH stdout/stderr stream, validates known frame types and step keys, redacts payloads before persistence or future broadcast, and maps accepted frames to `operation_runs`. Internal commands never perform outbound gateway callbacks just to report progress.
- **D20 Gateway-owned queueing and broadcasting.** This plan does not implement operator-client broadcasting, but the architecture must leave it straightforward: gateway jobs own queueing, operation status, stream consumption, redaction, and future WebSocket/SSE broadcast. Gateway-to-node execution remains SSH through hidden `internal:*` commands for this plan. If nodes later gain an API/WebSocket runtime, that runtime is another node-local transport that calls the same action/service layer and sends progress to the gateway; it is not the authority that broadcasts directly to operator clients.
- **D21 Long-running public write command shape.** Long-running public write commands such as `node:new`, `app:new`, `workspace:new`, `workspace:setup`, tool lifecycle commands, and `deploy:run` are gateway-orchestrated operations. The CLI starts the operation and renders gateway-authored operation state or stream frames; it must not become the workflow orchestrator. If a gateway operation dispatches node-local work, the gateway chooses the transport (`RemoteHostExecutor`, `RemoteOrbitRuntimeExecutor`, hidden `internal:*` over SSH, or a future node API runtime) and owns queueing, persistence, redaction, and future broadcast hooks. A future node API can replace SSH as a transport for eligible node-local work, but it calls the same action/service layer and reports progress back to the gateway operation pipeline rather than broadcasting directly to clients.
- **D22 `node:new` input split.** `node:new` uses `--template=<preset>` for named presets and `--roles=<csv>` for explicit programmatic role composition. `--template` and `--roles` are always mutually exclusive. `--operator` is mutually exclusive with `--roles` and with every template except the equivalent `--template=operator`. The previous repeatable role option and template custom escape hatch are retired. `--roles` accepts only canonical assignable role values (`app-dev`, `app-prod`, `database`, `agent`, `ingress`, `websocket`, `s3`) with no aliases; `app-development` and `app-production` remain template names only. Gateway bootstrap uses `--template=gateway`; gateway-coupled `gateway`, `vpn`, and `router` assignments are expanded by that template and are not public `--roles` values.

## File Map

### Product Docs

- Modify: `apps/docs/content/architecture.md`
- Modify: `apps/docs/content/tech-stack.md`
- Modify: `apps/docs/content/concepts.md`
- Modify: `apps/docs/content/execution-lanes.md`
- Modify: `apps/docs/content/domains/README.md`
- Modify: `apps/docs/content/domains/1_node/README.md`
- Modify: `apps/docs/content/domains/1_node/node-concepts.md`
- Modify: `apps/docs/content/domains/1_node/9_node-default/technical/1_node-default.md`
- Modify: `apps/docs/content/domains/2_gateway/gateway-concepts.md`
- Modify: `apps/docs/content/domains/2_gateway/1_gateway-add/technical/1_gateway-add.md`
- Modify: `apps/docs/content/domains/2_gateway/2_gateway-trust/technical/1_gateway-trust.md`
- Modify: `apps/docs/content/domains/11_operation/operation-concepts.md`
- Modify: `apps/docs/content/domains/11_operation/1_update/technical/1_update.md`
- Modify: `apps/docs/content/domains/16_dns/2_dns-list/technical/1_dns-list.md`
- Modify: `apps/docs/content/domains/authorization-matrix.md`
- Modify: `apps/docs/content/domains/15_agent-ide/README.md`
- Modify: `apps/docs/content/domains/15_agent-ide/agent-ide-concepts.md`
- Modify: `apps/docs/content/porting/testing-infrastructure.md`
- Modify: `apps/docs/content/testing/README.md`
- Modify: command contracts under `apps/docs/content/domains/**/technical/*.md` as each command family is ported
- Modify: `AGENTS.md`
- Modify: AI/project helper docs that mention the old launcher or gateway-Artisan public command path

### Core Protocol

- Modify: `packages/core/src/Http/JsonEnvelope.php`
- Create: `packages/core/src/Progress/ProgressEvent.php`
- Create: `packages/core/src/Progress/ProgressEventType.php`
- Create: `packages/core/src/Progress/ProgressEventEncoder.php`
- Create: `packages/core/src/Progress/ProgressEventDecoder.php`
- Audit and reuse: `packages/core/src/Enums/OperationStatus.php` already contains the target operation-run cases; rename only if a distinct `OperationRunStatus` name is justified and documented
- Keep: `packages/core/src/Security/OperationToken.php`
- Keep: `packages/core/src/Security/OperationTokenSigner.php`
- Keep: `packages/core/src/Security/OperationTokenVerifier.php`
- Test: `packages/core/tests/JsonEnvelopeTest.php`
- Test: `packages/core/tests/ProgressEventTest.php`
- Test: `packages/core/tests/OperationTokenTest.php`

### Launcher And CLI App

- Modify: `bin/orbit`
- Modify: `bin/install-orbit` tests, implementation only if the current symlink target regresses
- Modify: `apps/cli/orbit`
- Modify: `apps/cli/config/commands.php`
- Modify: `apps/cli/app/Commands/OrbitCommand.php`
- Create or rename: `apps/cli/app/Commands/GatewayCommand.php`
- Create: `apps/cli/app/Commands/LocalOnlyCommand.php`
- Create: `apps/cli/app/Commands/BootstrapGatewayCommand.php`
- Create: `apps/cli/app/Commands/StreamsGatewayProgress.php`
- Create: `apps/cli/app/Commands/Internal/InternalExecutorCommand.php`
- Create as needed: `apps/cli/app/Actions/**` or `apps/cli/app/Services/**` for node-local action/service classes behind non-trivial hidden commands
- Create: `apps/cli/app/Services/OrbitConfigStore.php`
- Create: public command classes under `apps/cli/app/Commands`
- Create: internal executor command classes under `apps/cli/app/Commands/Internal`
- Modify: `apps/cli/app/Services/GatewayApiClient.php`
- Create: `apps/cli/app/Services/GatewayStreamClient.php`
- Modify: `apps/cli/app/Providers/GatewayApiServiceProvider.php`
- Inspect (no required change yet): `apps/cli/app/Providers/OperationTokenGuardServiceProvider.php`. Phase 5's `InternalExecutorCommand` base may bind the verifier through this provider; only modify the provider if the binding changes.
- Test: `apps/cli/tests/Feature/PublicCommandForwardingTest.php`
- Test: `apps/cli/tests/Feature/InternalExecutorCommandTest.php`
- Test: `apps/cli/tests/Feature/LauncherContractTest.php`
- Test: `apps/cli/tests/Feature/CommandListVisibilityTest.php`
- Test: `apps/cli/tests/Feature/OrbitConfigStoreTest.php`

### Gateway App

- Modify: `apps/gateway/routes/api.php`
- Modify: `apps/gateway/app/Http/Controllers/Api/*Controller.php` as command families are ported
- Create: `apps/gateway/database/migrations/*_create_operation_runs_table.php`
- Create: `apps/gateway/app/Models/OperationRun.php`
- Create: `apps/gateway/app/Services/Operations/OperationRunRecorder.php`
- Create: `apps/gateway/app/Services/Operations/OperationResultHandler.php`
- Create as needed: `apps/gateway/app/Services/Operations/InternalExecutorProgressStream.php` or equivalent gateway-side parser/adapter for framed internal progress streams
- Modify: `apps/gateway/app/Services/Operations/OperationTokenFactory.php`
- Modify: `apps/gateway/app/Services/RemoteShell/RemoteLocalExecutor.php`
- Modify: `apps/gateway/app/Services/RemoteShell/LocalExecutorCommandBuilder.php`
- Modify: `apps/gateway/app/Console/Commands/*Command.php` as each public command is ported away from gateway Artisan
- Test: `apps/gateway/tests/Feature/InstallOrbitLauncherTest.php`
- Test: `apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php`
- Test: `apps/gateway/tests/Feature/Http/Api/**/*Test.php`
- Test: `apps/gateway/tests/Feature/Commands/**/*Test.php` during transition
- Test: `apps/gateway/tests/E2E/**/*Test.php` for integrated topology behavior

## Phase 1: Product Contract, Docs Drift, And Command Inventory

- [ ] Update `apps/docs/content/architecture.md` so the CLI section states that `bin/orbit` enters `apps/cli/orbit` for all public commands and hidden `internal:*` commands on clients, workload nodes, and gateway hosts.
- [ ] Update `apps/docs/content/architecture.md` so gateway-host public CLI calls have an explicit gateway-self API model: either HTTPS through the gateway WireGuard/orbit-caddy path or a documented local loopback client that preserves the same authorization and envelope behavior. Record the chosen model before Phase 4.
- [ ] Update `apps/docs/content/architecture.md` so the gateway-to-node path states: `gateway API -> authorization -> operation run record when applicable -> RemoteShell -> /usr/local/bin/orbit internal:* -> result parsed -> gateway-side persistence`.
- [ ] Update `apps/docs/content/tech-stack.md` so `apps/gateway/artisan` is described as gateway runtime and developer maintenance, not the public CLI target.
- [ ] Update `AGENTS.md` so repository guidance no longer describes the installed `orbit` launcher as dispatching to the gateway artifact based on node role. The exact target sentence in `AGENTS.md:25-27` reads: "The gateway entry point is `php apps/gateway/artisan` from the repository root, or `php artisan` from `apps/gateway/`. The host `orbit` launcher dispatches to the gateway or CLI artifact based on the node role." Replace the dispatch sentence with: "The host `orbit` launcher always executes `apps/cli/orbit`. Gateway maintenance uses `bin/orbit-gateway-artisan` or direct `php apps/gateway/artisan` in controlled gateway contexts only." Keep `bin/orbit-gateway-artisan` as the documented gateway maintenance entry point.
- [ ] Update `apps/docs/content/concepts.md` definitions for the Orbit launcher and host cwd context so they no longer say the launcher chooses a role-appropriate artifact or dispatches to `apps/gateway/artisan`.
- [ ] Update `apps/docs/content/domains/1_node/node-concepts.md` and `apps/docs/content/domains/1_node/README.md` to remove the transitional statement that the launcher dispatches gateway hosts to gateway Artisan or forwards unported commands as a target behavior. Specifically rewrite the "**Orbit launcher**" bullet in `node-concepts.md:43-50` and the dispatch paragraph at `node-concepts.md:271-274` to describe the post-migration always-CLI behavior, and update `domains/1_node/README.md:57-63` to describe the target contract with explicit local-only and bootstrap exceptions. Treat the "role-aware artifact" wording in `concepts.md:42` the same way.
- [ ] Update `apps/docs/content/domains/1_node/9_node-default/technical/1_node-default.md` so every `node:default` path is owned by CLI-local JSON config. `show`, `set`, `choose`, and `clear` may validate against the gateway where the contract requires it, but the default value is local CLI state. Document the operator-visible behavior change per decision D11: the default is now per-operator-host, not per-gateway, and `node:default` does not read from any gateway-side store. Phase 8 retires the `/api/nodes/default` endpoint and the `LocalNodeDefault` model.
- [ ] Update `apps/docs/content/domains/2_gateway/gateway-concepts.md`, `apps/docs/content/domains/2_gateway/1_gateway-add/technical/1_gateway-add.md`, and `apps/docs/content/domains/2_gateway/2_gateway-trust/technical/1_gateway-trust.md` so local gateway config lives in `~/.config/orbit/config.json` with gateway endpoint, gateway WireGuard IP, CA PEM path, CA hash/fingerprint, timeout, and active gateway name. Remove any product claim that gateway trust lives only in gateway-app SQLite.
- [ ] Update `apps/docs/content/domains/16_dns/2_dns-list/technical/1_dns-list.md`, `apps/docs/content/domains/11_operation/1_update/technical/1_update.md`, and `apps/docs/content/domains/authorization-matrix.md` so `dns:list`, `dns:resolve-tld`, `update`, `gateway:add`, `gateway:trust`, and `node:default` are explicitly local-only or bootstrap commands rather than gateway-backed command families.
- [ ] Update `apps/docs/content/domains/15_agent-ide/README.md`, `apps/docs/content/domains/15_agent-ide/agent-ide-concepts.md`, and `apps/docs/content/porting/testing-infrastructure.md` to remove stale "role-aware artifact" wording that says gateway hosts use `apps/gateway/artisan` through the installed `orbit` command.
- [ ] Update `apps/docs/content/execution-lanes.md` to preserve the documented lane split: bootstrap and host substrate work stay in `RemoteHostExecutor`; runtime/container-specific work stays in its documented runtime executor lane; `RemoteLocalExecutor` invokes hidden CLI helpers only for packaged node-local Orbit logic.
- [ ] Update `apps/docs/content/execution-lanes.md` so the installed host launcher is described as always entering `apps/cli/orbit`; any remaining gateway Artisan usage must be through explicit maintenance entry points such as `bin/orbit-gateway-artisan` or direct `php apps/gateway/artisan` calls in controlled gateway contexts.
- [ ] Update `apps/docs/content/execution-lanes.md` to state that `RemoteLocalExecutor` cannot invoke public commands and that operation tokens are checked once at internal-command entry before side effects.
- [ ] Update `apps/docs/content/execution-lanes.md` to document the thin-command/action boundary: CLI command classes are adapters; non-trivial gateway behavior lives in gateway services; non-trivial node-local internal behavior lives in CLI action/service classes with typed inputs and typed results.
- [ ] Update `apps/docs/content/execution-lanes.md` to document the future internal progress-frame contract from D19. Record that gateway queueing, operation state, redaction, and future broadcasting are gateway-owned; hidden commands may emit framed observations over the gateway-owned transport but must not call back to the gateway or broadcast to clients directly.
- [ ] Add a new subsection to `apps/docs/content/execution-lanes.md` titled "Result-boundary redaction patterns" that enumerates the full secret pattern set every gateway-side and command-side redaction step must scrub: `operation_token`, `executor_secret`, `password`, `bearer`, `secret`, `_token`, `api_key`, PEM blocks (`-----BEGIN [A-Z ]+-----` through `-----END [A-Z ]+-----`). Today the doc only documents `--operation-token=` and the literal minted token value; the broader pattern set is enforced by code but not yet in product docs. The same subsection records that redaction applies at both the internal-command result-boundary (per D14) and the gateway `OperationResultHandler` (per Phase 7); both layers reference this subsection by anchor.
- [ ] Update `apps/docs/content/domains/README.md` to keep command contracts as product authority while clarifying that implementation command classes move to `apps/cli`, not `apps/gateway`.
- [ ] Update `apps/docs/content/domains/README.md` so the shared command rule says gateway-backed public commands call the gateway API, while documented local-only and bootstrap commands are explicit exceptions. Do not keep wording that says every CLI command call is HTTPS to the gateway.
- [ ] Document the CLI local config file: default path `~/.config/orbit/config.json`, optional `ORBIT_CONFIG_PATH` override, directory mode `0700`, file mode `0600`, atomic writes, no CLI SQLite database, no persisted bearer identity, and the command families allowed to mutate it.
- [ ] Document the local config migration contract: import or dual-read behavior for `local_gateway_settings`, `local_node_defaults`, and existing `apps/cli/.env` E2E settings; the owner for each field after migration; and the cutoff point where SQLite/local `.env` stops being a source of truth for CLI-local state.
- [ ] Update `apps/docs/content/concepts.md` and `apps/docs/content/domains/11_operation/operation-concepts.md` to define `OperationRun` as execution state for queued, streamed, or gateway-to-node work. Make clear it does not replace activity logs.
- [ ] Add or update gateway operator workflow docs explaining how gateway maintainers invoke scheduler, migrations, E2E helpers, `orbit:internal:bake-*`, and other gateway Artisan maintenance after the public launcher always enters CLI.
- [ ] Add or update API compatibility docs for the canonical JSON envelope. Include whether a versioned endpoint, compatibility shim, or coordinated breaking-change window is used.
- [ ] Keep the progress-event protocol at `tree`, `step`, `complete`, and `error` by default. Do not add a public `heartbeat` event unless product docs are deliberately changed; existing SSE comment keepalives may remain transport-only.
- [ ] Run docs drift searches and add every relevant hit to the Phase 1 patch. Include `apps/docs/content/porting`, `apps/docs/content/domains/2_gateway/**`, and the shared "every CLI command call is a request to the gateway over HTTPS" wording at `apps/docs/content/domains/README.md:67-71`:

  ```bash
  rg -n "apps/gateway/artisan|forwards unported|role-aware artifact|role-appropriate Orbit artifact|launcher dispatches|dispatches directly|public command forwarding|every CLI command call is a request" apps/docs/content .agents docs/superpowers bin docker
  ```

- [ ] Review every docs drift hit manually. Do not remove legitimate "role-aware" language from doctor/probe scope docs that is about role-scoped checks rather than launcher dispatch.
- [ ] Run command inventory from the repo root:

  ```bash
  bin/orbit-gateway-artisan list --format=json > /tmp/orbit-gateway-commands.json
  apps/cli/orbit list --format=json > /tmp/orbit-cli-commands.json
  ```

- [ ] Classify every command in `/tmp/orbit-gateway-commands.json` into one of these buckets inside the implementation notes for the first PR:
  - public gateway-backed command to port to `apps/cli`
  - public local-only command to port to `apps/cli`
  - public bootstrap command to port to `apps/cli`
  - hidden internal executor command to keep or create in `apps/cli`
  - gateway runtime command to keep under `apps/gateway/artisan`
  - E2E/developer support command to keep under `apps/gateway/artisan`
  - framework/dev/vendor command to hide from the public CLI surface
- [ ] Confirm the inventory includes at least these easy-to-miss current public commands: `app:exec`, `agent-ide:message`, `cf-dns:list`, `cf-zone:list`, `database:schema`, `deploy:history`, `deploy:step-add`, `deploy:step-list`, `deploy:step-remove`, `dns:resolve-tld`, `firewall:list`, `php:use`, `profile`, and `update`.
- [ ] Confirm the inventory includes current registration/adoption/log commands that are easy to miss, including `node:register` if present, app registration/adoption flows such as `app:register`, and `workspace:log`.
- [ ] Classify every `orbit:internal:*` gateway Artisan command, including `BakeAgentNodeCommand`, `BakeAppNodeCommand`, `BakeIngressNodeCommand`, `BootstrapGatewayLocalCommand`, `BuildRuntimeImagesCommand`, `DatabaseQueryLocalCommand`, `DetectPlatformCommand`, `InstallOrbitDnsCommand`, and `PinNodeHostKeysCommand`, as gateway Artisan/E2E support unless a later command contract explicitly moves it.
- [ ] Classify local and bootstrap exceptions explicitly, including `gateway:add`, `gateway:trust`, `node:new --template=gateway`, and caller-local `node:default` paths.
- [ ] Treat the completed command classification as a hard gate. Phase 4 may not start until the matrix is committed, the local/bootstrap docs match it, and every temporary compatibility allow-list entry has an owner family and removal phase.
- [ ] Commit the classification matrix as a real artifact at `docs/superpowers/notes/cli-command-classification-YYYY-MM-DD.md` (or under `apps/docs/content/operator/` if it becomes operator-facing). The matrix is the source of truth for compatibility allow-list entries and Phase 11 removal owners. No allow-list entry may exist without a row in this matrix.
- [ ] List `apps/cli/orbit:8-50` and `apps/cli/orbit:73-85` in the Phase 1 docs drift output so Phase 4 has explicit anchors for replacing `fallbackToGatewayArtisanWhenCommandIsUnported`, `commandNameFromArgv`, `isNativeCliCommand`, and `passthruCommand` and Phase 11 has explicit anchors for final removal.
- [ ] Scrub `apps/cli/.env`, `apps/cli/.env.example`, and AI/project helper docs for `ORBIT_GATEWAY_IDENTITY` references. After decision D2 this key is removed from `apps/cli/config/orbit.php` and should not be documented as product config.
- [ ] In the docs drift sweep output, also list any matches found by `rg -n "ORBIT_IS_GATEWAY" apps/gateway/app/E2E apps/gateway/tests docker`. These are E2E topology references; Phase 4 retargets them.
- [ ] Run docs verification:

  ```bash
  composer docs-lint
  ```

  Expected: PASS.

## Phase 2: Core Protocol And API Contract Foundation

- [ ] Apply decision D4: Phase 2 lands a single coordinated breaking-change window. Before editing `packages/core/src/Http/JsonEnvelope.php`, commit a coordination artifact at `docs/superpowers/notes/cli-envelope-cutover-2026-05-28.md` containing: (a) the canonical shape and a small example payload; (b) the cutover release tag/date; (c) a table of every gateway controller in `apps/gateway/app/Http/Controllers/Api/**` with columns `controller`, `current shape (ok|success|mixed)`, `target shape`, `audit status (pending|done)`; (d) a list of known direct consumers (Solo orchestration scratchpads under `docs/superpowers/plans/solo-orchestration/`, any Codex/Solo loop role that parses `orbit ... --json`, repository scripts in `bin/`, gateway controller fakes used in `apps/cli/tests` and `apps/gateway/tests`, and any external scripts the owner is aware of). Phase 2 cannot land its `JsonEnvelope` rewrite until every row in the controller table has `audit status = done`.
- [ ] Publish the canonical envelope contract + cutover date in product documentation, not only in `docs/superpowers/notes/`. Either append a "Canonical JSON Envelope" section to `apps/docs/content/architecture.md` (Command and API model area) or create `apps/docs/content/domains/api-envelope.md`. Per `AGENTS.md:41-42`, `docs/superpowers/notes/` is session-artifact territory and is not product authority; direct API consumers (Solo agents, Codex, external scripts) must be able to find the cutover contract in product docs.
- [ ] Change `packages/core/src/Http/JsonEnvelope.php` so success responses use exactly one top-level `success` key and failure responses use exactly one top-level `error` key, aligning the helper with the canonical gateway controller shape that already exists in many API controllers.
- [ ] Update `packages/core/tests/JsonEnvelopeTest.php` to assert this success shape:

  ```json
  {"success":{"data":{"example":true},"meta":{"request_id":"abc"}}}
  ```

- [ ] Update `packages/core/tests/JsonEnvelopeTest.php` to assert this failure shape:

  ```json
  {"error":{"code":"validation_failed","message":"Invalid input.","meta":{"field":"name"}}}
  ```

- [ ] Inventory gateway controller response shapes by controller and route, not only by helper usage. Start with:

  ```bash
  rg -n "return response\\(\\)->json\\(" apps/gateway/app/Http/Controllers/Api
  rg -n "JsonEnvelope::success|JsonEnvelope::failure" apps
  ```

  The second sweep finds every existing call site of the helper that will change shape; record each in the audit table from the Phase 2 coordination artifact.

- [ ] Classify each gateway API response as canonical, legacy-compatible, or pending-family-port. Do not rely on the helper change alone to standardize existing controllers.
- [ ] Decide whether CLI JSON output passes through the gateway envelope or renders a parsed response into a new envelope. The target is one canonical envelope in CLI output, never nested `success.success` or mixed `ok`/`success` shapes.
- [ ] Update CLI tests and command docs for the JSON output break caused by `apps/cli/app/Commands/OrbitCommand.php` moving from `ok` envelopes to `success` and `error` envelopes.
- [ ] Add `ProgressEventType` with these cases only: `Tree`, `Step`, `Complete`, and `Error`.
- [ ] Add `ProgressEvent` with readonly fields: `type`, `payload`.
- [ ] Add `ProgressEventEncoder` for SSE frame encoding used by the gateway.
- [ ] Add `ProgressEventDecoder` for CLI-side SSE frame decoding.
- [ ] Keep core progress classes framework-neutral. They may encode and decode payload strings and arrays, but they must not depend on Symfony Console, Laravel HTTP responses, Eloquent, or gateway streaming classes.
- [ ] Decide whether the gateway keeps wrapping existing `App\Support\Streaming` emitters or replaces them with core encoders. Existing `: heartbeat` SSE comments remain transport keepalives and must not be decoded as product events unless docs change first.
- [ ] Reuse `packages/core/src/Enums/OperationStatus.php` for operation run state. It already has the target cases `Queued`, `Running`, `Succeeded`, `Failed`, `Expired`, and `Rejected`. Do not introduce a parallel `OperationRunStatus` enum. The same enum covers token state and operation-run state.
- [ ] Run core tests:

  ```bash
  cd packages/core && vendor/bin/pest --compact
  ```

  Expected: PASS.

## Phase 3: CLI Local JSON Configuration, Trust Material, And Gateway-Self Client

This phase gives every node a small local CLI config before the launcher stops entering gateway Artisan on gateway hosts. The config is a JSON file, not a database. Apply decisions D1, D2, D3, D8 in this phase.

- [ ] Create `apps/cli/app/Services/OrbitConfigStore.php`.
- [ ] Make the default config path `$HOME/.config/orbit/config.json` (operator user on a developer Mac, `~orbit` on a provisioned node, per decision D8). Multi-user nodes that share one Orbit install are out of scope.
- [ ] Add an `ORBIT_CONFIG_PATH` override for tests, isolated E2E nodes, and special deployments.
- [ ] Store this initial shape. Do not store a bearer-style gateway identity. `meta.imported_from` and `meta.imported_at` are written only when the file is created from the gateway SQLite migration path described later in this phase:

  ```json
  {
    "schema_version": 1,
    "active_gateway": "default",
    "gateways": {
      "default": {
        "url": "https://10.6.0.1",
        "wireguard_ip": "10.6.0.1",
        "ca_pem_path": "/home/orbit/.config/orbit/gateways/default/ca.pem",
        "ca_sha256": "sha256-hex",
        "ca_fingerprint": "sha256:fingerprint",
        "timeout": 30,
        "self_mode": "wireguard_https"
      }
    },
    "defaults": {
      "node": null,
      "profile": null
    },
    "meta": {
      "imported_from": null,
      "imported_at": null
    }
  }
  ```

- [ ] Apply decision D1: `gateways.<name>.self_mode` is always `"wireguard_https"` on gateway hosts. The gateway host writes its own WireGuard address to `wireguard_ip` and `url`, and its own CA PEM to `ca_pem_path`. There is no privileged local-loopback bypass. The first-gateway bootstrap brings WireGuard up before any gateway-host CLI call is required.
- [ ] Apply forward-compatibility for `schema_version`: `OrbitConfigStore` refuses to read a config whose major version is newer than the binary supports, and ignores unknown sibling fields it does not understand. Document the next-version migration path before bumping the version.

- [ ] Write the parent directory with owner-only permissions: `0700` for `~/.config/orbit` and gateway subdirectories.
- [ ] Write the config file atomically by writing a temporary file in the same directory, applying mode `0600`, and renaming it into place.
- [ ] Define behavior for existing config files with broader permissions: fix permissions silently when owner matches, otherwise refuse with an Orbit-handled `config_insecure_permissions` failure.
- [ ] Validate reads so malformed JSON, missing object roots, wrong scalar types, and unreadable files produce Orbit-handled CLI failures, not PHP warnings.
- [ ] Apply decision D13: keep `apps/cli/config/orbit.php` env-only and move the env-then-JSON precedence into `GatewayApiServiceProvider`'s binding closure for `GatewayApiClient`. The closure asks `OrbitConfigStore` for the active gateway entry, then overlays env values when present. Document the mechanism on both `OrbitConfigStore` and `GatewayApiServiceProvider` class doc blocks. Add a `config:cache` warning near the JSON reader: cached config does not see file edits until the next command run rebuilds the binding; this is acceptable because the provider closure is lazy.
- [ ] Update `apps/cli/config/orbit.php` so env vars remain explicit overrides, then fall back to `OrbitConfigStore` values:
  - `ORBIT_GATEWAY_URL` overrides the active gateway `url`
  - `ORBIT_GATEWAY_CA_PEM` overrides the active gateway `ca_pem_path`
  - `ORBIT_GATEWAY_CA_SHA256` overrides the active gateway `ca_sha256`
  - `ORBIT_GATEWAY_TIMEOUT` overrides the active gateway `timeout`
  - `gateway.identity` is removed from `apps/cli/config/orbit.php`. Tests that previously relied on `ORBIT_GATEWAY_IDENTITY` move to the WireGuard header path gated by `orbit.e2e_trust_wireguard_header` / `orbit.trust_wireguard_proxy_header`.
  - executor env vars continue to come from the launcher environment
- [ ] Apply decision D2: rewire `GatewayApiServiceProvider` and `GatewayApiClient` so they no longer construct or pass an `$identity` bearer. Remove the `$identity` constructor argument and the `withToken(...)` branch in `GatewayApiClient::pendingRequest()`. The CLI never spoofs `X-Orbit-WireGuard-Ip`. Production identity comes from orbit-caddy's WireGuard peer header; developer-Mac usage relies on the WireGuard tunnel peer address.
- [ ] Migrate tests that previously authenticated via `Http::withToken(...)` (for example `apps/cli/tests/Feature/GatewayApiClientTest.php`, `apps/cli/tests/Feature/PublicCommandForwardingTest.php`, and any gateway fakes that expect a bearer header) to assert the request reaches a gateway whose middleware treats the caller as a WireGuard peer. Use the existing `orbit.e2e_trust_wireguard_header` toggle on the gateway side.
- [ ] Apply decision D3: executor secrets stay in `${ORBIT_REPO}/apps/gateway/.env` on every node role for the duration of this plan. No new per-node secret file is introduced. The launcher only exports those vars for `internal:*` invocations. See Phase 4 for the launcher-side mechanism and tests.
- [ ] Apply decision D16: define the first-gateway bootstrap sequence in `apps/docs/content/domains/2_gateway/1_gateway-add/technical/1_gateway-add.md`. `BootstrapGatewayCommand` writes `~/.config/orbit/config.json` (and `~/.config/orbit/gateways/default/`) without first reaching the gateway, because the WireGuard endpoint and CA material are produced locally during gateway provisioning. After provisioning, the gateway runtime is started, and subsequent CLI calls route through the WireGuard HTTPS path.
- [ ] Apply decision D15: document the dev-Mac workflow under `apps/docs/content/domains/2_gateway/1_gateway-add/technical/2_gateway-add_on-client.md`. The first `gateway:add` on a fresh operator Mac authenticates via the gateway's public CA bootstrap endpoint (`/api/ca/root`) and an out-of-band CA fingerprint; it does not require WireGuard up. Every subsequent CLI call requires either the Mac to be a WireGuard peer of that gateway, or the gateway to have `orbit.trust_wireguard_proxy_header` enabled with an upstream proxy forwarding `X-Orbit-WireGuard-Ip`.
- [ ] Add a CLI failure-mode rule: when the gateway is not reachable because WireGuard is down or no peer route exists, `GatewayApiClient` returns the distinct error code `gateway_unreachable_wireguard`, not the generic `gateway_unavailable`. Add a focused test.
- [ ] Define the explicit local-state migration contract. The CLI's first run after upgrade must:
  - read the most recent `local_gateway_settings` row and the most recent `local_node_defaults` row from `apps/gateway/database/database.sqlite`, if present and readable;
  - import the `local_gateway_settings` row into `gateways.default` (the only existing slug) with `url`, `wireguard_ip`, `ca_pem_path`, `ca_sha256` taken from the row;
  - import `LocalNodeDefault::current()->default_node_name` into `defaults.node`;
  - write the equivalent JSON config to `~/.config/orbit/config.json` if the file does not already exist;
  - never overwrite an existing JSON config with values from SQLite (idempotent);
  - record provenance in `meta.imported_from` and `meta.imported_at`;
  - log a one-line audit entry naming the source database path.
- [ ] Audit every reachable caller of `LocalGatewaySettings::current()` and `LocalNodeDefault::query()` across the gateway codebase by running:

  ```bash
  rg -n "LocalNodeDefault" apps/gateway/app
  rg -n "LocalGatewaySettings" apps/gateway/app
  ```

  Use the grep output as the source of truth, not the lists below. The two stores answer different questions: `LocalGatewaySettings` is the gateway-runtime's own self-trust material (kept per D17); `LocalNodeDefault` is operator default-node state (removed per D11). For each caller, classify as one of:
  - **(a) default-node consumer** — currently uses `LocalNodeDefault::query()` to resolve the target node. Retire the read; require client-side `--node=X` per D11. Known type (a) callers from the current tree include `NodeDefaultCommand`, `AppNewCommand`, `ToolInstallCommand`, `ToolStartCommand`, `ToolStopCommand`, `ToolRestartCommand`, `ToolRemoveCommand`, `ToolShowCommand`, `ToolLogsCommand`, `ProxyAddCommand`, `DoctorCommand`, `Concerns/AuthorizesAgentToolSelf`, `AbstractFirewallStoreCommand`, `FirewallRemoveCommand`.
  - **(b) gateway-runtime self-trust consumer** — uses `LocalGatewaySettings::current()` for runtime self-trust. Keep, document under D17. Known type (b) callers include `NodeUpdateCommand`, `NodeAgentIdeCommand`, `AgentIdeMessageCommand`, `UpdateAllCommand`, `ProcessEventNotifierRenderer`, `GatewayConnector`, `GatewayStreamTransport`, and parts of `NodeNewCommand`.
  - **(c) D8 owner-boundary collision** — runs as the gateway-runtime OS user (not the operator user) and therefore cannot read `OrbitConfigStore`. Known: `PhpRuntimeManager`, `NodesProbe`, `DoctorCommand` when invoked by the scheduler. For type (c), pick a policy and apply it consistently: either require `--node=X` to be passed in by the caller, or store the resolved default on the operation row at dispatch and read from there, or fail loudly when no target is supplied. The plan must commit to one policy; "read from OrbitConfigStore" is not an option for type (c).
  Verify any class flagged as type (b) does not also have a `LocalNodeDefault` read (some commands touch both). Re-cross-check `apps/gateway/app/E2E/Support/**` separately because the test surface is wider than the runtime surface. Add a Phase 8 sub-bullet per command family flagged as type (a) or type (c).
- [ ] Update installer/bootstrap flows that know the gateway endpoint or trust material to create or update `~/.config/orbit/config.json` on the target node.
- [ ] Update E2E helper setup that currently writes `apps/cli/.env` gateway settings so prepared topologies and tests write JSON config, or explicitly dual-write during the migration window.
- [ ] Update `gateway:add`, `gateway:trust`, and caller-local `node:default` contracts so they mutate this JSON file through `LocalOnlyCommand` when they are ported.
- [ ] Add a real `gateway:status` endpoint to `apps/gateway/routes/api.php` as a Phase 5 prerequisite (covered there). Do not leave `GatewayStatusCommand` pointing at `/api/status` if no such route exists.
- [ ] Add `apps/cli/tests/Feature/OrbitConfigStoreTest.php` covering default path resolution, override path resolution, missing file, malformed JSON, atomic writes, env override precedence, permission repair/refusal, migration import, owner-only permission intent, and a permissions-readback check that asserts the saved file is mode `0600` (not world-readable) after `OrbitConfigStore::save()`.
- [ ] Add a CLI feature test proving a gateway-backed command can read gateway URL and CA trust material from the JSON config when no `ORBIT_GATEWAY_URL` env var is set.
- [ ] Add a gateway or E2E installer test proving installed nodes get a usable CLI config file during bootstrap.
- [ ] Run focused config tests:

  ```bash
  bin/orbit-cli-pest --compact --filter=OrbitConfigStore
  bin/orbit-cli-pest --compact --filter=GatewayApiClient
  ```

  Expected: PASS.

## Phase 4: Launcher Switch With A Narrow Compatibility Bridge

- [ ] Retarget every gateway-host call site that currently shells out to `orbit <gateway-artisan-command>` and depends on gateway-Artisan dispatch. Use `bin/orbit-gateway-artisan`, explicit `php apps/gateway/artisan`, or the E2E runtime equivalent instead.
- [ ] Exhaustively inspect and retarget `apps/gateway/app/E2E/Support/E2EGatewayApi.php`, topology providers, Docker/Incus topology builders, helper scripts, and tests that invoke gateway-host `orbit tinker`, `orbit migrate`, `orbit route:list`, `orbit schedule:run`, `orbit config:show`, `orbit db:*`, `orbit make:*`, `orbit queue:*`, `orbit cache:*`, or `orbit orbit:internal:*`.
- [ ] Retarget provisioning and prepared-topology paths that invoke gateway Artisan internal commands through the public `orbit` launcher, including `orbit:internal:bootstrap-gateway-local`, `orbit:internal:detect-platform`, and the bake/build/pin/install internal family. Use `bin/orbit-gateway-artisan`, direct `php apps/gateway/artisan`, or a topology helper that is explicitly not the public launcher.
- [ ] Run the gateway-Artisan call-site sweep before changing `bin/orbit`. Add a third sweep that catches variable-substituted launcher paths (e.g., `local cmd="$bin tinker"`):

  ```bash
  rg -nP "(['\"\\s]|^)orbit\\s+(tinker|migrate|route:list|schedule:run|config:show|db:|make:|queue:|cache:)" apps bin tests apps/docs
  rg -n "orbit orbit:internal:|orbit:internal:|apps/gateway/artisan|bin/orbit-gateway-artisan" apps/gateway bin docker apps/gateway/tests apps/docs/content
  rg -n "bin/orbit|/usr/local/bin/orbit" apps/gateway/app/E2E apps/gateway/tests/E2E docker
  ```

- [ ] Modify `bin/orbit` so it always exports `ORBIT_APP=cli` and executes `${ORBIT_REPO}/apps/cli/orbit "$@"`.
- [ ] Keep `ORBIT_REPO` and `ORBIT_HOST_CWD` export behavior in `bin/orbit`.
- [ ] Apply decision D3 with a concrete launcher mechanism: `bin/orbit` must resolve `ORBIT_COMMAND` via `command_name "$@"` **before** deciding whether to source `apps/gateway/.env`. Today's `bin/orbit:134` calls `export_local_executor_environment` unconditionally before `ORBIT_COMMAND` is resolved on line 136 — that order must be reversed. After the resolve, if the command matches `is_local_executor_command()`, source the .env and export `ORBIT_EXECUTOR_SECRET` and `ORBIT_NODE_IDENTITY`. `ORBIT_OPERATION_TOKEN_SECRET` (the gateway-side mint secret) is **never exported by the launcher** to any node-local CLI process. For all other commands the launcher must **actively `unset` `ORBIT_EXECUTOR_SECRET`, `ORBIT_OPERATION_TOKEN_SECRET`, and `ORBIT_NODE_IDENTITY`** (not merely refuse to re-export) so an inherited env from the parent shell does not leak into the child process. Use bash quoting that does not break on `--option=value with spaces` arguments.
- [ ] Extend `is_local_executor_command()` so it accepts every hidden `internal:*` command currently registered in `apps/cli/app/Commands/Internal/**`, including `internal:executor:verify`, `internal:wg-easy:state`, `internal:workspace-adapter:lookup`, and `internal:workspace-adapter:update`. The list must stay in sync with `apps/cli/config/commands.php` hidden registration; add a regression test that compares the two.
- [ ] Remove the `gateway_flag()` helper, the `ORBIT_IS_GATEWAY` `env_value(...)` call, and the gateway flag branch from `bin/orbit`. Do not leave `gateway_flag()` as a stub; remove the function entirely.
- [ ] Remove the old local-executor special-case branch from `bin/orbit` because all public and hidden commands now enter the CLI app via the always-cli exec.
- [ ] Hide `internal:workspace-adapter:update` in `apps/cli/config/commands.php` as part of this phase (not Phase 5). The launcher switch routes this command through the CLI on gateway hosts, so any command-visibility test will fail until the registration is hidden.
- [ ] Add a launcher hygiene test that runs a fake public command through `bin/orbit` on a simulated gateway host (with `apps/gateway/.env` containing executor secrets) and asserts the spawned CLI process does not have `ORBIT_EXECUTOR_SECRET`, `ORBIT_OPERATION_TOKEN_SECRET`, or `ORBIT_NODE_IDENTITY` in its environment. Add a second assertion: for an `internal:*` command on the same simulated host, the child process must have `ORBIT_EXECUTOR_SECRET` and `ORBIT_NODE_IDENTITY` but must **not** have `ORBIT_OPERATION_TOKEN_SECRET` (the mint secret is gateway-only).
- [ ] Add the inverse launcher hygiene test: simulate a parent shell that already exports `ORBIT_EXECUTOR_SECRET` (operator session), run a public command through `bin/orbit`, and assert the child CLI process does NOT receive it. This catches the pre-existing `if [ -z "${ORBIT_EXECUTOR_SECRET:-}" ]` guard in `bin/orbit:88` silently passing through inherited env. The launcher must actively unset the secret vars for public commands, not just refuse to re-export.
- [ ] Add an explicit allow-list sync test: parse the `is_local_executor_command()` cases in `bin/orbit` and the hidden `commands` list in `apps/cli/config/commands.php`, and assert both contain the same set of `internal:*` names. A divergence between the two is the bug class this whole phase is designed to prevent.
- [ ] Exhaustive sweep for E2E topology references that set `ORBIT_IS_GATEWAY` or shell `orbit ...` for gateway-role nodes:

  ```bash
  rg -n "ORBIT_IS_GATEWAY|gateway_flag|->putEnv\\('ORBIT_IS_GATEWAY'|setenv.*ORBIT_IS_GATEWAY" apps/gateway/app/E2E apps/gateway/tests docker
  ```

  Classify each `ORBIT_IS_GATEWAY` match as **remove** or **keep**: removable matches are E2E topology builders and provisioning scripts that need to be retargeted to `bin/orbit-gateway-artisan`. Legitimate keep matches include `apps/gateway/.env.example`, gateway runtime config that reads the flag, and docs that describe gateway-runtime behavior. Retarget every "remove" match in `apps/gateway/app/E2E/Support/**` (including `DockerTopologyBuilder.php`, `IncusTopologyBuilder.php`, `E2EGatewayApi.php`, prepared-topology providers), `apps/gateway/tests/E2E/**` (including `VerificationScriptsTest.php`, `DockerTopologyBuilderTest.php`, `PreparedTopologyContractTest.php`, `IncusTopologyBuilderTest.php`, `E2ECurrentCheckoutTest.php`, `DockerRuntimeImageContractTest.php`), and any `docker/**` helper that constructs gateway-host `orbit` invocations. Use `bin/orbit-gateway-artisan` or direct `php apps/gateway/artisan` for maintenance commands; for E2E provisioning commands routed through `orbit:internal:*`, use direct `php apps/gateway/artisan` calls on the gateway container. Paste the final classified sweep output (post-retarget, with "kept" rows annotated) into the Phase 4 PR description.
- [ ] Before the first Phase 4 edit, commit the **starting** inventory at `docs/superpowers/notes/phase4-pre-sweep-inventory-YYYY-MM-DD.txt`. This is the raw output of all three rg sweeps above on the commit point where Phase 4 begins. It documents what the retarget work must cover and provides a baseline the post-retarget artifact can be diffed against.
- [ ] Commit the post-retarget audit artifact at `docs/superpowers/notes/phase4-e2e-gateway-host-orbit-invocations.md`. One line per remaining gateway-host `orbit <cmd>` (or equivalent direct `php apps/gateway/artisan` call) in E2E support, tests, and `docker/`. Each line contains: command, file:line, justification (must reference the Phase 1 matrix entry showing the command is hidden `internal:*` or an explicit maintenance path). This artifact is reviewed as part of the Phase 4 merge gate; an incomplete artifact blocks merge.
- [ ] Add or keep tests proving `/usr/local/bin/orbit` links to `${TARGET_DIR}/bin/orbit`, never to `${TARGET_DIR}/apps/gateway/artisan` and never directly to `${TARGET_DIR}/apps/cli/orbit`. The current installer already appears to link to `bin/orbit`, so prefer a regression test over a no-op implementation edit.
- [ ] Replace the broad `apps/cli/orbit` passthrough with an explicit allow-list for unported public product commands only.
- [ ] Ensure the temporary allow-list never forwards `internal:*`, gateway runtime commands, framework/dev/vendor commands, or unknown commands.
- [ ] Add a compatibility-bridge policy: no newly ported or newly created command may use the bridge, every existing bridge entry must have an owner family and removal phase, and Phase 11 may not start until the allow-list is empty.
- [ ] Add a tracking test that every allow-listed fallback command is present in the Phase 1 command matrix and has an owner family for removal.
- [ ] Add or update `apps/cli/tests/Feature/LauncherContractTest.php` to assert that public commands and hidden `internal:*` commands enter the CLI app on gateway and non-gateway hosts.
- [ ] Update `apps/gateway/tests/Feature/InstallOrbitLauncherTest.php` atomically in this phase. Use descriptive `it()` anchors instead of line numbers because the file evolves. Enumerate every existing bridge-presence assertion that must be removed or inverted:
  - the "dispatches gateway-role nodes through the gateway artifact" test: invert so it expects `apps/cli/orbit` with `ORBIT_APP=cli`
  - the "dispatches workload nodes through the cli artifact" test: keep behavior, reaffirm `apps/cli/orbit`
  - the "default unconfigured" test: assert `apps/cli/orbit`
  - the "keeps unported command compatibility..." block: remove the `fallbackToGatewayArtisanWhenCommandIsUnported` presence assertion, the `passthruCommand(PHP_BINARY` presence assertion, the `dirname(__DIR__, 2).'/apps/gateway/artisan'` presence assertion, and the `ln -sf "$TARGET_DIR/apps/gateway/artisan"` negative-presence assertion (which becomes redundant once the launcher cannot dispatch there)
  - add a positive assertion: hidden `internal:workspace-adapter:update` enters the CLI app on a gateway host
  The test must pass in a single Phase 4 patch; split-phase edits are not allowed because the suite would be red between them.
- [ ] Update `apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php` and any launcher probe tests so they no longer assert that `bin/orbit` contains `apps/gateway/artisan` as the gateway-host public target.
- [ ] Add or update tests proving unknown commands fail inside the CLI app instead of being forwarded to gateway Artisan.
- [ ] Add gateway-host regression tests proving `orbit migrate`, `orbit tinker`, and other gateway Artisan commands are not reachable through the public `orbit` command, and that the documented maintenance entry point works.
- [ ] Add an explicit verification gate at the end of Phase 4: `composer test:e2e:docker` and `composer test:e2e:provision` must pass in a clean workspace after the launcher switch lands, with no remaining expectations that public user commands on gateway-role nodes dispatch to `apps/gateway/artisan`. The Phase 4 PR is not merged until this gate is green. The Phase 4 `rg` retargeting sweep is necessary but not sufficient. Per gate G3, the Phase 4 PR also requires: (a) a named live-node verification owner; (b) a 72-hour post-merge observation window with regression notes recorded in the PR thread; (c) at least one live gateway and one live workload node exercised before the merge proceeds.
- [ ] Run launcher, CLI, and integrated smoke tests:

  ```bash
  bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/InstallOrbitLauncherTest.php
  bin/orbit-cli-pest --compact
  composer test:e2e:docker
  composer test:e2e:provision
  ```

  Expected: PASS.

## Phase 5: CLI Command Foundation

- [ ] Refactor `apps/cli/app/Commands/OrbitCommand.php` into `GatewayCommand`, or rename it if that is cleaner, as the base for public commands that call the gateway API. It already owns `wantsJson()`, gateway calls, and JSON rendering, so avoid duplicating those helpers.
- [ ] Create `apps/cli/app/Commands/LocalOnlyCommand.php` as the base for public commands that mutate only caller-local config or environment.
- [ ] Create `apps/cli/app/Commands/BootstrapGatewayCommand.php` as the base for public commands that run before a gateway API exists.
- [ ] Add helpers on `GatewayCommand` for:
  - `wantsJson()`
  - `renderSuccess(array $data, array $meta = [])`
  - `renderFailure(string $code, string $message, array $meta = [])`
  - `gatewayGet(string $path, array $query = [])`
  - `gatewayPost(string $path, array $payload = [])`
  - `gatewayDelete(string $path, array $payload = [])`
- [ ] Create `apps/cli/app/Commands/StreamsGatewayProgress.php` for commands that request `Accept: text/event-stream` and render decoded `ProgressEvent` frames.
- [ ] Create `apps/cli/app/Commands/Internal/InternalExecutorCommand.php` as the base for hidden internal executor commands.
- [ ] Add a thin-command guardrail to the command foundation tests/review checklist: command `handle()` methods stay as input/transport/render adapters, while workflow orchestration, persistence policy, retry behavior, and node-local business logic live in services/actions.
- [ ] Refactor existing internal commands onto `InternalExecutorCommand`: `internal:executor:verify`, `internal:wg-easy:state`, `internal:workspace-adapter:lookup`, and `internal:workspace-adapter:update`.
- [ ] Make `InternalExecutorCommand` validate `--operation-token` through the existing token guard before command-specific validation or side effects.
- [ ] Make `InternalExecutorCommand` support two explicit output contracts: completion mode writes one typed JSON result, and future streaming mode writes framed progress events only. Do not allow arbitrary console output to be mixed into either contract.
- [ ] For every new or materially changed internal command that performs non-trivial node-local work, create or reuse a typed action/service class. The hidden command validates token/input, delegates to that action/service, and serializes the typed result or typed progress frames.
- [ ] Add a result-boundary redaction contract to `InternalExecutorCommand`: before the command serializes its JSON result to stdout, the base scans the result array against the patterns documented in the result-boundary redaction subsection added to `apps/docs/content/execution-lanes.md` in Phase 1 (see the Phase 1 task that adds this subsection enumerating the full pattern set) (`operation_token`, `executor_secret`, `password`, `bearer`, `secret`, `_token`, `api_key`, PEM blocks). If any forbidden key or value pattern is present, the command fails hard with code `result_contains_secret` before any output is written. The gateway-side `OperationResultHandler` rejection from Phase 7 remains as defense in depth. Add a `tests/Feature/Commands/Internal/InternalExecutorRedactionTest.php` that exercises both the command-side and handler-side rejection paths.
- [ ] Make internal command allow-listing role-scoped where applicable. For example, `internal:wg-easy:state` should only be allowed for nodes whose documented role permits that operation.
- [ ] Make every internal executor command include `{--operation-token=}` and `{--json}` in its signature.
- [ ] Ensure `apps/cli/config/commands.php` explicitly registers and hides every existing hidden command. `internal:workspace-adapter:update` is already hidden in Phase 4; this step asserts the full set: `internal:executor:verify`, `internal:wg-easy:state`, `internal:workspace-adapter:lookup`, `internal:workspace-adapter:update`.
- [ ] Update `apps/cli/app/Services/GatewayApiClient.php` to parse the canonical `success` and `error` envelopes from core.
- [ ] Create `apps/cli/app/Services/GatewayStreamClient.php` for SSE requests and decoded progress event callbacks.
- [ ] Convert `apps/cli/config/commands.php` to explicit product command registration. The current file path-discovers commands under `app_path('Commands')`; replace that with a named list of the form `'commands' => [GatewayStatusCommand::class, ...], 'hidden' => [WorkspaceAdapterUpdateCommand::class, ...]`, and assert that `app/Commands/Internal/**` is not path-scanned. Land this conversion before the first Phase 6 read-family port PR so newly added classes never auto-register through discovery. Add a `CommandListVisibilityTest` covering: every product command in `apps/cli/app/Commands` is present in `apps/cli/orbit list`, every `internal:*` command is hidden, and no Laravel Zero/vendor framework command is present in the default human listing.
- [ ] Make `StreamsGatewayProgress` a PHP trait used by streaming command classes (e.g., `class WorkspaceSetupCommand extends GatewayCommand { use StreamsGatewayProgress; }`), not a separate base class. This avoids a deep parent chain when a streaming command also needs `GatewayCommand` helpers.
- [ ] Hide or remove Laravel Zero/framework/dev commands from normal public CLI output, including `app:build`, `app:install`, `app:rename`, `make:*`, `stub:*`, `test`, and any vendor commands that are not part of the Orbit product CLI.
- [ ] Add a real `/api/status` route (or equivalent documented endpoint) to `apps/gateway/routes/api.php` and an `apps/gateway/app/Http/Controllers/Api/GatewayStatusController.php` that returns a `success` envelope with the gateway status payload. Update `apps/gateway/tests/Feature/Http/Api/GatewayStatusControllerTest.php` to assert the route, the success envelope, and the failure envelope. Before adding the controller, run `bin/orbit-gateway-artisan route:list --path=status --method=GET` and confirm no existing route conflicts; paste the empty output into the PR. Note: `apps/cli/app/Commands/GatewayStatusCommand.php` is broken today (it calls `/api/status`, which does not exist) and is currently allow-listed in `apps/cli/orbit` `isNativeCliCommand()` so it never reaches the bridge. Phase 5 is the moment `gateway:status` actually starts working.
- [ ] Run focused CLI tests:

  ```bash
  bin/orbit-cli-pest --compact --filter=GatewayCommand
  bin/orbit-cli-pest --compact --filter=LocalOnlyCommand
  bin/orbit-cli-pest --compact --filter=BootstrapGatewayCommand
  bin/orbit-cli-pest --compact --filter=InternalExecutorCommand
  ```

  Expected: PASS.

## Phase 6: Port Read-Only Public Commands First

Port read commands before write commands because they validate transport, authorization, envelope parsing, and renderers without node mutation risk. After each family is ported, remove that family from the temporary compatibility allow-list.

- [ ] Refactor existing `apps/cli/app/Commands/GatewayStatusCommand.php` onto the new `GatewayCommand` base and canonical envelope. The real `gateway:status` API endpoint is added in Phase 5; Phase 6 only consumes it.
- [ ] Port a genuinely unported read command next, such as `activity:list`.
- [ ] Port activity reads: `activity:list`, `activity:show`.
- [ ] Port node reads: `node:list`, `node:show`, `node role:list`.
- [ ] Port app reads: `app:list`, `app:show`. Verify whether `app:root` has a documented read path before adding it here; if it is write-only, keep it in Phase 8.
- [ ] Port workspace reads: `workspace:list`, `workspace:show`, `workspace:history`, `workspace-setup-step:list`, `workspace-teardown-step:list`.
- [ ] Port process reads: `process:list`, `process:logs` without `--follow`.
- [ ] Port proxy reads: `proxy:list`.
- [ ] Port schedule reads: `schedule:list`, `schedule:show`, `schedule:logs`.
- [ ] Port tool reads: `tool:list`, `tool:show`, `tool:logs` without `--follow`.
- [ ] Port `tool:credentials` only after adding sensitive-read permission and redaction tests. Do not treat credentials output as an ordinary read.
- [ ] Port PHP/database reads: `php:list`, `database:list`, `database:show`, `database:tables`, `database:describe`, `database:schema`.
- [ ] Port Cloudflare reads: `cf-dns:list`, `cf-zone:list`.
- [ ] Port firewall reads: `firewall:list`.
- [ ] Port deploy reads: `deploy:history`, `deploy:step-list`.
- [ ] Port profile/status reads that are classified as public in Phase 1, including `profile` if it remains public.
- [ ] For every read family above, preflight the gateway route with `bin/orbit-gateway-artisan route:list` or a focused route assertion before creating the CLI command. Do not assume endpoints exist because a gateway command exists.
- [ ] For each command above, add a CLI feature test that fakes the gateway API response and asserts human output plus `--json` output.
- [ ] For each gateway endpoint used above, keep or add a gateway API feature test that asserts authorization, success envelope shape, and failure envelope shape.
- [ ] Keep gateway Artisan public command registration until the family has CLI/API parity and is removed from the compatibility allow-list.
- [ ] Run focused tests after each family:

  ```bash
  bin/orbit-cli-pest --compact
  bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Commands/Activity apps/gateway/tests/Feature/Commands/Nodes apps/gateway/tests/Feature/Commands/Apps apps/gateway/tests/Feature/Commands/Workspaces apps/gateway/tests/Feature/Commands/Processes apps/gateway/tests/Feature/Commands/Proxy apps/gateway/tests/Feature/Commands/Schedule apps/gateway/tests/Feature/Commands/Tools apps/gateway/tests/Feature/Commands/Php apps/gateway/tests/Feature/Commands/Database apps/gateway/tests/Feature/Commands/Cloudflare apps/gateway/tests/Feature/Commands/Firewall apps/gateway/tests/Feature/Commands/Deploy
  ```

  Expected: PASS.

## Phase 7: Add Gateway Operation Run Records

`operation_runs` track queued, streamed, or gateway-to-node execution state. They do not replace activity logs, and they are not required for every read or caller-local command.

- [ ] Create gateway migration `create_operation_runs_table` with columns. Per decision D5, `id` is the per-attempt id; `operation_id` groups attempts. Per decision D2/D3, redaction lives in the recorder, not the column:
  - `id` UUID primary key — the per-attempt operation-run id; never reused across re-mints
  - `operation_id` UUID, indexed — the logical operation id; the same value across re-mints of the same logical operation
  - `internal_command` nullable string — required for hidden `internal:*` dispatch rows; null for streamed gateway-only rows
  - `operation_type` nullable string — required for streamed gateway-only rows; optional for internal dispatch rows when the dispatch belongs to a named workflow
  - `lane` string — constrained to `host`, `runtime`, `local`, or `gateway` via SQL CHECK or application-level invariant tested explicitly. `gateway` covers gateway-internal streamed work that does not dispatch through a `Remote*Executor` (e.g., long-running gateway-side orchestrations that emit progress events).
  - `status` string — uses `OperationStatus` enum values
  - `caller_node_id` nullable foreign id
  - `target_node_id` nullable foreign id
  - `correlation_id` nullable UUID string
  - (note) per decision D14, `dispatch_activity_id` and `completion_activity_id` are removed. The relationship is reversed: `operation_runs` is the parent; activity rows reference `operation_runs.id`. See the activity-table migration step below.
  - `queue` nullable string
  - `started_at` nullable timestamp
  - `finished_at` nullable timestamp
  - `exit_code` nullable integer
  - `result` nullable JSON/text — must be stored only after `OperationResultHandler` redaction
  - `error` nullable JSON/text — must be stored only after redaction
  - `stdout_summary` nullable text — must be stored only after redaction
  - `stderr_summary` nullable text — must be stored only after redaction
  - `created_at` and `updated_at`
- [ ] Create `apps/gateway/app/Models/OperationRun.php`.
- [ ] Create `OperationRunRecorder` with methods: `queued()`, `running()`, `succeeded()`, `failed()`, `rejected()`, `expired()`.
- [ ] Define `internal_command` as the hidden CLI command name, such as `internal:workspace-adapter:lookup`.
- [ ] Define `operation_type` as the gateway-owned workflow type, such as `workspace.setup` or `tool.install`.
- [ ] Apply decision D14: add an `operation_run_id` nullable foreign key column to the Spatie `activity_log` table (gateway SQLite). Create a fresh migration `*_add_operation_run_id_to_activity_log.php` that uses `config('activitylog.table_name')` as the target table name; do not assume `activities`. The migration timestamp must be later than `create_operation_runs_table` so the FK target exists when this column is added. Activity rows under the `local_executor.dispatching` and `local_executor.completed` channels written by `RemoteLocalExecutor` carry the matching `operation_runs.id` in this new column. The recorder writes the `operation_runs` row first, then the activity row referencing it. Preserve all other activity logging required by `apps/docs/content/execution-lanes.md`.
- [ ] Add a gateway feature test that, for a given `operation_id`, asserts every linked activity row's `description` field and `properties` JSON value contain no raw `--operation-token` substring and no values matching the redacted secret patterns documented in the result-boundary redaction subsection added to `apps/docs/content/execution-lanes.md` in Phase 1 (see the Phase 1 task that adds this subsection enumerating the full pattern set) (`operation_token`, `executor_secret`, `password`, `bearer`, `secret`, `_token`, `api_key`, and PEM blocks). Use the Spatie activity log's existing columns (`description`, `properties`); do not introduce non-existent `command_line` or `metadata` columns on the activity table.
- [ ] Define the row creation rule: hidden `internal:*` dispatches always create an operation run; streamed gateway commands create one when their command contract says they are long-running operations; `RemoteHostExecutor` and `RemoteOrbitRuntimeExecutor` calls create one only when they correspond to a recorded gateway operation; pure read commands and `LocalOnlyCommand` paths never create one.
- [ ] Create `OperationResultHandler` that maps one operation type plus one typed remote result into gateway-owned writes.
- [ ] Ensure `OperationResultHandler` rejects unrecognized result keys with `operation.result_unrecognized`.
- [ ] Ensure `OperationResultHandler` rejects any result payload that contains keys matching token, secret, or password patterns documented in the result-boundary redaction subsection added to `apps/docs/content/execution-lanes.md` in Phase 1 (see the Phase 1 task that adds this subsection enumerating the full pattern set): `operation_token`, `executor_secret`, `password`, `bearer`, `secret`, `_token`, `api_key`, and any value that looks like a PEM block. Add a focused test that proves a fabricated result with each of those keys is rejected before `result` is written to `operation_runs`.
- [ ] Apply the same recognition and redaction policy to progress payloads that may later be persisted or broadcast: framed progress keys must be known for the operation contract, values must be redacted before persistence, and unknown progress payloads fail closed with a stable error code.
- [ ] Apply decision D5: explicitly allow re-minting tokens for the same logical operation. The recorder creates a fresh `operation_runs.id` on every attempt and copies the prior `operation_id`. Add a regression test that re-minting twice produces two `operation_runs` rows with the same `operation_id` and different `id`.
- [ ] Update `RemoteLocalExecutor::runInternal()` to create or update an `operation_runs` row around every internal executor dispatch.
- [ ] Keep existing activity logging for audit visibility; operation runs are execution state, not a new authoritative state family.
- [ ] Add a retention/cleanup decision for `operation_runs`. Default: keep active rows indefinitely, retain terminal rows for 90 days, and keep only redacted summaries after cleanup unless a command contract requires longer audit retention. Read the retention window from `config('orbit.operation_runs.retention_days', 90)` so operators can tune without code changes. Add the `operation_runs` block (with `retention_days` key) to `apps/gateway/config/orbit.php` as part of the Phase 7 migration patch; without it, the config call returns the default but operators have no documented way to override. The 90-day window applies only to `operation_runs`. Activity log retention is governed by its own policy and is not changed by this plan.
- [ ] Add gateway feature tests for successful, failed, rejected, expired, and token-redacted operation records.
- [ ] Run focused gateway tests:

  ```bash
  bin/orbit-gateway-pest --compact --filter=OperationRun
  ```

  Expected: PASS.

## Phase 8: Port Mutating, Local-Only, And Bootstrap Public Commands

Port writes after the read path and operation records are stable. Public CLI entry moves first; deeper gateway service internals can still use the existing RemoteShell lanes until Phase 10 classifies and migrates them.

- [ ] Port local gateway relationship commands through `LocalOnlyCommand` or documented bootstrap flow as appropriate: `gateway:add`, `gateway:trust`.
- [ ] Port first-gateway bootstrap through `BootstrapGatewayCommand`, including the no-gateway path for `node:new --template=gateway`.
- [ ] Port caller-local config paths through `LocalOnlyCommand`, including every `node:default` path: `show`, `choose`, `set`, and `clear`. These commands may call the gateway to validate a node when needed, but the stored default remains local JSON config.
- [ ] Apply decision D11: retire the gateway-side `node:default` surface in the same family port. Remove `Route::get|put|delete('/nodes/default', ...)` from `apps/gateway/routes/api.php:212-214`, delete the `NodeDefaultController` (or downgrade to an explicit 410-Gone for one release window if direct API callers exist), drop the `LocalNodeDefault` model and the `local_node_defaults` migration, and remove every gateway-side reader (per D11 they cannot read a per-OS-user store; every caller is ported to either client-side resolution with `--node=X` or to a direct payload field). Mention the operator-visible behavior change (default is now per-operator-host) in the PR description. Apply gate G4: this PR carries a named live-node verification owner and a 72-hour post-merge observation window.
- [ ] Port local DNS commands through `LocalOnlyCommand`: `dns:list` and `dns:resolve-tld`.
- [ ] Port self-update through the local-only/bootstrap lane as documented by the update command contract. Do not route `update` through gateway-backed write APIs unless product docs are deliberately changed.
- [ ] Before porting long-running write families, classify each command as immediate, queued, streamed, or log-streamed. Slow provisioning/orchestration commands must enter a gateway operation path and render gateway-authored state; do not bury multi-step workflow orchestration in the CLI command class.
- [ ] Port node writes: `node:new`, `node:update`, `node:remove`, `node:grant`, `node:revoke`, `node:permissions`, `node role:add`, `node role:remove`, `node:agent-ide`. `node:new` is a long-running operation candidate per D21: the CLI may initiate and render it, but gateway services own queueing, operation state, transport selection, redaction, and future broadcast hooks. `node:new --template=gateway` keeps the documented bootstrap exception, but its CLI command still stays adapter-thin per D18 and D22.
- [ ] Port app writes: `app:new`, `app:register`, app adoption/registration flows classified in Phase 1, `app:remove`, `app:prune`, `app:root`, `app:agent-ide`, `app:worker`, `app:exec`. `app:new` is a long-running operation candidate per D21: app creation/provisioning work belongs behind gateway services and operation state, not inside a fat CLI command.
- [ ] Port agent IDE writes or message commands classified as public, including `agent-ide:message`.
- [ ] Port workspace writes: `workspace:new`, `workspace:setup`, `workspace:remove`, `workspace:exec`, `workspace-setup-step:add`, `workspace-setup-step:remove`, `workspace-teardown-step:add`, `workspace-teardown-step:remove`.
- [ ] Port process writes: `process:add`, `process:edit`, `process:remove`, `process:start`, `process:stop`, `process:restart`.
- [ ] Port proxy writes: `proxy:add`, `proxy:remove`.
- [ ] Port schedule writes: `schedule:add`, `schedule:remove`, `schedule:run`.
- [ ] Port tool writes: `tool:install`, `tool:remove`, `tool:start`, `tool:stop`, `tool:restart`, `tool:update`, `tool:reload`, `tool:reconfigure`.
- [ ] Port firewall writes: `firewall:allow`, `firewall:deny`, `firewall:remove`.
- [ ] Port Cloudflare writes: `cf-dns:add`, `cf-dns:remove`, `cf-cache:flush`, `cf-cache-rule:add`, `cf-cache-rule:remove`, `cf-ssl:enable`, `cf-ssl:disable`.
- [ ] Port VPN runtime commands through gateway-mediated endpoints that preserve the documented VPN execution lane: `vpn-client:list`, `vpn-client:new`, `vpn-client:enable`, `vpn-client:disable`, `vpn-client:remove`, `vpn-web-ui:change-password`.
- [ ] Port PHP/local runtime commands according to the Phase 1 lane classification, including `php:use`.
- [ ] Port database writes: `database:add`, `database:update`, `database:remove`, `database:attach`, `database:detach`, `database:query`.
- [ ] Port deploy writes: `deploy:run`, `deploy:step-add`, `deploy:step-remove`.
- [ ] Port profile according to the Phase 1 lane classification if it remains public.
- [ ] For every destructive command above, keep destructive consent in the CLI input mode and keep gateway-side authorization independent of CLI prompts.
- [ ] For every command above, add CLI tests for human success, JSON success, validation failure, gateway unavailable, and authorization failure.
- [ ] After each family is ported, remove the family from the temporary compatibility allow-list.
- [ ] Run family tests after each family port:

  ```bash
  bin/orbit-cli-pest --compact
  bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Commands/Gateway apps/gateway/tests/Feature/Commands/Nodes apps/gateway/tests/Feature/Commands/Apps apps/gateway/tests/Feature/Commands/Workspaces apps/gateway/tests/Feature/Commands/Processes apps/gateway/tests/Feature/Commands/Proxy apps/gateway/tests/Feature/Commands/Schedule apps/gateway/tests/Feature/Commands/Tools apps/gateway/tests/Feature/Commands/Dns apps/gateway/tests/Feature/Commands/Firewall apps/gateway/tests/Feature/Commands/Vpn apps/gateway/tests/Feature/Commands/Cloudflare apps/gateway/tests/Feature/Commands/Database apps/gateway/tests/Feature/Commands/Deploy
  ```

  Expected: PASS.

## Phase 9: Port Streamed And Long-Running Commands

- [ ] Apply decision D10: CLI streamed commands always request `Accept: text/event-stream`. In `--json` mode the CLI consumes the same SSE stream and emits only the final `complete` or `error` frame as one JSON object on stdout. No parallel non-SSE JSON endpoint is added.
- [ ] Ensure `GatewayStreamClient` consumes the HTTP stream incrementally. It must not call APIs that buffer the entire response body before decoding progress events.
- [ ] Use `ProgressEventDecoder` in CLI for `tree`, `step`, `complete`, and `error` frames. Ignore SSE comment keepalives such as `: heartbeat` as transport details, not product progress events. Gateway-side keepalive emission in `apps/gateway/app/Http/Gateway/GatewayStreamTransport.php` stays unchanged; only the CLI decoder drops the comment frames. Add a `ProgressEventDecoderTest` case asserting that lines starting with `:` are silently skipped.
- [ ] Treat a stream close without `complete` or `error` as `gateway_unavailable`.
- [ ] Port streamed progress for `node:new`, `app:new`, `workspace:new`, `workspace:setup`, `tool:install`, `tool:start`, `tool:stop`, `tool:restart`, `tool:update`, `tool:reload`, `tool:reconfigure`, `doctor`, `update:all`, and `deploy:run`.
- [ ] For `node:new` and `app:new`, prove the streamed surface is backed by gateway operation state. The gateway owns queueing and emits the progress frames that a future WebSocket/SSE broadcaster can forward; the CLI consumes and renders those frames but does not synthesize progress around a blocking workflow.
- [ ] Keep `process:logs --follow`, `tool:logs --follow`, `deploy:log`, and `schedule:logs` as log-stream commands with their documented log stream shape, not progress tree events. Classify `workspace:log` from its command contract before moving it to this lane; do not make it streaming as an incidental porting change.
- [ ] Add CLI tests with fake SSE frames for success, gateway error, malformed frame, comment keepalive handling, and unexpected stream close.
- [ ] Add gateway API tests proving long-lived streams do not use public command passthrough and emit canonical progress events.
- [ ] Keep streamed progress authored by the gateway operation layer. The CLI may render progress and JSON completion, but it must not synthesize fake progress frames around blocking calls.
- [ ] Run focused stream tests:

  ```bash
  bin/orbit-cli-pest --compact --filter=Stream
  bin/orbit-gateway-pest --compact --filter=Stream
  ```

  Expected: PASS.

## Phase 10: Migrate Gateway-To-Node Work Into Hidden Executor Commands Carefully

This phase migrates only post-baseline node tasks that belong in `RemoteLocalExecutor`. First provisioning and host bootstrap remain shell-based until the target node has a working Orbit checkout, launcher, PHP CLI, WireGuard identity, and executor secret. Host substrate mutation stays in its documented executor lane unless Phase 1 docs explicitly changed that contract.

- [ ] Keep the Phase 5-refactored hidden commands on the `InternalExecutorCommand` base: `internal:executor:verify`, `internal:wg-easy:state`, `internal:workspace-adapter:lookup`, `internal:workspace-adapter:update`. Do not refactor them a second time in this phase; Phase 10 only verifies their role-scoped allow-list behavior and uses them as examples for later lane-approved migrations.
- [ ] Inventory all `RemoteShell::run()`, `RemoteHostExecutor`, `RemoteOrbitRuntimeExecutor`, and `RemoteLocalExecutor` call sites.
- [ ] Classify each call site as:
  - keep shell/bootstrap
  - keep runtime/container executor
  - migrate to hidden `internal:*`
  - requires product-doc change before migration
- [ ] For each migration candidate, classify the operation boundary before creating commands: use one internal command per cohesive node-local operation, not one command per visible progress label. Split only at real retry, reuse, permission, isolation, or transaction boundaries.
- [ ] Do not move proxy artifact writes, Caddy reloads, firewall mutation, package installation, service-manager mutation, or other host substrate work into `internal:*` unless `apps/docs/content/execution-lanes.md` is deliberately changed and tested first.
- [ ] Add a closed allow-list to `LocalExecutorCommandBuilder`. Today the builder only validates the `internal:[a-z0-9:_-]+` pattern; it does not have a per-command allow-list. This phase introduces the named allow-list of permitted commands; reject any `internal:*` name not in the allow-list with a specific command-not-allowed error. This is a new check, not an extension of an existing list.
- [ ] Make the allow-list role-scoped. A hidden command is allowed only when both the command name and the target node role match the documented lane.
- [ ] Keep gateway Artisan `orbit:internal:*` maintenance/provisioning commands out of the hidden CLI `internal:*` executor namespace unless Phase 1 docs deliberately move one. Bake/build/detect/bootstrap/pin/install gateway internals stay gateway-only by default.
- [ ] For each lane-approved migration, create one hidden `internal:*` command that accepts typed options, validates the operation token before side effects, emits a typed JSON result, and is hidden from normal CLI lists.
- [ ] For each lane-approved migration, create or reuse a node-local action/service class behind the hidden command. The command class is an adapter around token validation, typed input mapping, action/service invocation, and typed output rendering.
- [ ] Replace the matching gateway call site with `RemoteLocalExecutor::runInternal()` for completion-style work.
- [ ] For long-running lane-approved migrations whose command contract requires node-originated progress, add a separate streaming internal executor path such as `RemoteLocalExecutor::streamInternal()` or an equivalent service. It must parse the D19 framed stream, update `operation_runs`, and feed gateway-owned broadcast hooks later without requiring node callbacks to the gateway.
- [ ] Ensure every remote executor result is parsed by a gateway-side operation handler before gateway state is changed.
- [ ] Add tests proving direct invocation without a valid token fails before side effects for every new internal command class.
- [ ] Add gateway tests proving raw operation tokens never appear in activity logs, operation records, exceptions, stdout summaries, or stderr summaries. Specifically assert that the `result` and `error` JSON columns on `operation_runs` are scrubbed by `OperationResultHandler` before they are persisted (not after).
- [ ] Run focused executor tests:

  ```bash
  bin/orbit-cli-pest --compact --filter=Internal
  bin/orbit-gateway-pest --compact --filter=RemoteLocalExecutor
  ```

  Expected: PASS.

## Phase 11: Retire Gateway Public Commands And Remove Compatibility

- [ ] Confirm the temporary CLI compatibility allow-list is empty.
- [ ] Remove `fallbackToGatewayArtisanWhenCommandIsUnported()` from `apps/cli/orbit`.
- [ ] Remove `passthruCommand()` from `apps/cli/orbit`.
- [ ] Remove the gateway Artisan public command registration for each ported public command.
- [ ] Keep gateway Artisan commands that are runtime-only: migrations, scheduler, E2E support, docs tooling, explicit internal maintenance commands, and classified gateway-only `orbit:internal:*` support commands.
- [ ] Edit `apps/gateway/app/Console/Kernel.php`: it exists and is authoritative for command registration in this app (`apps/gateway/bootstrap/app.php:31` binds it). Do not introduce a new console kernel or move registration into `bootstrap/app.php`.
- [ ] Add a gateway test asserting `bin/orbit-gateway-artisan list` does not expose public product commands after the final family is ported, but still exposes runtime/maintenance commands: `migrate`, `tinker`, `schedule:run`, `queue:*`, `cache:*`, `db:*`, `make:*`, and all `orbit:internal:*` bake/build/bootstrap/pin/install commands.
- [ ] Add a CLI test asserting `apps/cli/orbit list` exposes public product commands, hides `internal:*` by default, hides framework/dev commands not owned by Orbit, and never shows any command whose primary implementation remains under `apps/gateway/artisan` after the matrix is complete.
- [ ] After explicit user approval, delete or archive gateway command tests that assert gateway Artisan public command behavior after matching CLI and gateway API tests cover the same command contract.
- [ ] Run command visibility tests:

  ```bash
  bin/orbit-cli-pest --compact --filter=CommandListVisibility
  bin/orbit-gateway-pest --compact --filter=CommandListVisibility
  ```

  Expected: PASS.

## Phase 12: Integrated Verification

- [ ] Run CLI, core, docs, and gateway tests:

  ```bash
  composer test
  ```

  Expected: PASS.

- [ ] Run formatting and static analysis:

  ```bash
  composer quality-check
  ```

  Expected: PASS.

- [ ] Run Docker E2E for the launcher, CLI-to-gateway API, and internal executor path:

  ```bash
  composer test:e2e:docker
  ```

  Expected: PASS.

- [ ] Run Incus E2E when launcher/installer changes touch VM topology, WireGuard identity, first-gateway bootstrap, or provisioning internals:

  ```bash
  composer test:e2e:incus
  ```

  Expected: PASS when the lane is available in the local environment.

- [ ] Run provisioning E2E when changes touch bootstrap, installer, launcher installation, first-gateway setup, or baseline node preparation:

  ```bash
  composer test:e2e:provision
  ```

  Expected: PASS.

- [ ] Search for stale gateway-public-command coupling across code, docs, tooling, and AI helper files. The pattern is intentionally broader than Phase 4 because Phase 12 must catch references in any context, including variable-assigned paths. Include `bin/install-orbit` and `apps/gateway/tests/Feature` in the sweep so the installer/test pair cannot drift:

  ```bash
  rg -n "fallbackToGatewayArtisanWhenCommandIsUnported|passthruCommand|public command forwarding|forwards unported|apps/gateway/artisan|role-aware artifact|role-appropriate Orbit artifact|ORBIT_IS_GATEWAY|ORBIT_GATEWAY_IDENTITY" bin apps packages apps/docs/content docs/superpowers .agents docker apps/gateway/tests
  ```

  Expected: no remaining references except historical plans, intentional gateway maintenance docs (for example `apps/docs/content/architecture.md` describing `bin/orbit-gateway-artisan`), and explicit `bin/orbit-gateway-artisan` mentions.

## Execution Order

1. Product docs, docs drift sweep, command inventory, and lane classification.
2. Core envelope, progress protocol, and API compatibility contract.
3. CLI local JSON configuration at `~/.config/orbit/config.json`, gateway trust material, local-state migration, and gateway-self client wiring. Phase 4 is blocked until this phase defines gateway-host self-calls and first-gateway config write order.
4. Launcher switch to always enter CLI with a narrow temporary compatibility bridge.
5. CLI command base classes, explicit command registration, gateway clients, and internal command base refactor.
6. Read-only command families, removing compatibility family-by-family.
7. Operation run records for queued, streamed, or gateway-to-node execution state.
8. Mutating, local-only, and bootstrap command families, removing compatibility family-by-family.
9. Streamed and long-running commands.
10. Hidden executor command migration for lane-approved gateway-to-node work.
11. Gateway public command retirement and final compatibility removal.
12. Full verification.

## Open Questions To Resolve Before Implementation

The previous open questions are now pinned in the **Decisions Recorded** section above:

- Compatibility-bridge lifetime → **D6**: bounded by the committed classification matrix, bridge allow-list, and removal owners; no calendar or release-duration estimate.
- Envelope versioning vs shim vs window → **D4**: coordinated breaking-change window, single release boundary.
- Operator-visible `operation:*` commands → **D9**: out of scope for this plan; possible follow-up.
- Gateway-host self-client → **D1**: HTTPS over WireGuard with self-trusted CA PEM.
- Operation token TTL during long internal commands → **D7**: entry-only; expiry is informational after start.

One open question remains:

- Does `RemoteLocalExecutor` need to expand beyond its current narrow lane in `apps/docs/content/execution-lanes.md` to cover more post-baseline host work? The default in this plan is **no expansion**. Any expansion requires a docs-first change in a separate plan.

## Self-Review

- Spec coverage: covers public CLI migration, gateway authority, local/bootstrap exceptions, hidden executor tasks, core package boundaries, progress streaming, operation records, compatibility bridge removal, and final gateway command retirement.
- Placeholder scan: no placeholder tasks or deferred behavior markers are intentionally present.
- Type consistency: operation run, progress event, JSON envelope, internal executor, launcher, compatibility bridge, and RemoteLocalExecutor terms are used consistently across phases.
- Migration impact on operator behavior: the only operator-visible storage/semantics change is `node:default`, which moves from per-gateway shared state to per-operator-host local state (decision D11). The CLI envelope shape changes once at the Phase 2 cutover (decision D4); all other ports preserve current behavior or fix existing bugs (notably `gateway:status`). No new operator-required env vars; `ORBIT_GATEWAY_IDENTITY` is removed (D2).
