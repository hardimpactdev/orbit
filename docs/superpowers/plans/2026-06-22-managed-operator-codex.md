# Managed Operator Codex Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let roleless active operator nodes opt into gateway SSH management with `node:manage`, make tool targeting eligible for any active visible non-gateway node when the tool supports that node's OS, and register app projects in Codex App through `app:codex`.

**Architecture:** Keep the node model unchanged: no new role, no managed flag, no transport object. `node:manage` is a caller-local opt-in command that installs the gateway SSH public key locally, then calls typed gateway APIs to persist existing node fields, pin the host key by WireGuard address, and verify normal SSH. Tool definitions declare supported operating systems; roles may include baseline tools to materialize on a node, but role membership is not the general eligibility gate for user-directed tool commands. `app:codex` is an app-facing gateway-backed command that resolves the app and target node, then uses a narrow Codex App service to edit only `~/.codex/codex-app/config.json` over gateway remote shell and apply the Codex deep link.

**Tech Stack:** Laravel Zero CLI in `apps/cli`, Laravel gateway API in `apps/gateway`, Pest, typed gateway HTTP API over WireGuard, existing `RemoteShell`, existing SSH host key pinning, existing node access authorization.

---

## Current Conflicts And Gaps

- `apps/docs/content/domains/1_node/**` has no `node:manage` contract. Add the command docs before tests or code.
- `apps/docs/content/domains/5_app/README.md` has no `app:codex` entry and no command contract. Add the command docs before tests or code.
- `apps/docs/content/domains/3_tool/README.md` has a closed supported-tool table with no `codex-app`. Add catalog docs before registering the tool.
- `apps/docs/content/domains/3_tool/**` still describes tool support in role-and-platform terms, and `tool:install` docs describe required active roles for role baseline tools. Update the tool domain docs first so user-directed tool targeting is active visible non-gateway node plus OS support, while roles only include baseline tool intent.
- `apps/docs/content/product-decisions.md` has no line for the approved direction that Codex App is an operator-node tool, not an Agent IDE adapter, and no line for the new tool eligibility direction. Add current 2026-06-22 decision lines.
- `apps/gateway/app/Http/Controllers/Api/NodeUpdateController.php` rejects several operator-node metadata changes and `UpdateNodeApiRequest` does not accept `user` or `platform`. Do not overload `node:update`; add a narrow self-management endpoint.
- `apps/gateway/app/Services/Tools/ToolRegistry.php` and `apps/gateway/app/Http/Controllers/Api/Concerns/ResolvesVisibleToolNodes.php` resolve tool `--node` targets through `activeToolHostNodeIds()`, excluding roleless operators and other active non-tool-host nodes. Replace that hard target gate with active visible non-gateway node resolution.
- `apps/gateway/app/Contracts/ToolDefinition.php` has only `requiredNodeRole()`, and `ToolInstallController`/`ToolInstaller` use that role metadata as an installation gate. Replace the user-directed eligibility model with tool OS support plus a gateway exclusion. Keep role baseline metadata only for role convergence/materialization if still needed.
- Gateway SSH public-key derivation exists only as private provisioning code in `GatewayNodeCreator::gatewaySshPublicKey()`. Extract it into a reusable gateway service for provisioning and `node:manage`.
- Local platform detection exists in the gateway app (`App\Services\Platform\PlatformDetector`), not the CLI app. Add a CLI-local detector with the same output format or move a pure detector to shared core if the implementation worker finds an existing shared pattern.

## Implementation Scope

In scope:

- `orbit node:manage [--user=<user>] [--json]`
- gateway management key API and operator self-management API
- explicit tool `--node=<node>` target eligibility for active visible non-gateway nodes
- `codex-app` tool definition and docs
- `orbit app:codex add|remove|list ... --node=<target-node>`
- fixed-path Codex App config merge at `~/.codex/codex-app/config.json`
- applying `codex://codex-app/apply-config`

Out of scope:

- any `operator` or `workstation` role
- any `managed` database flag
- generic transport selection
- public or local hostnames for gateway-to-operator SSH when `wireguard_address` exists
- Agent IDE adapter integration for Codex App
- arbitrary file management on operator nodes
- per-node Codex SSH alias overrides
- role-based tool install eligibility as a general rule
- `workspace:codex`, registering Orbit workspaces as separate Codex projects, or managing Codex-created worktrees

## Files

Documentation:

- Modify: `apps/docs/content/product-decisions.md`
- Modify: `apps/docs/content/domains/1_node/README.md`
- Create: `apps/docs/content/domains/1_node/16_node-manage/node-manage.md`
- Create: `apps/docs/content/domains/1_node/16_node-manage/technical/1_node-manage.md`
- Create: `apps/docs/content/domains/1_node/16_node-manage/technical/2_node-manage_on-client.md`
- Create: `apps/docs/content/domains/1_node/16_node-manage/technical/5.1_node-manage_input-mode_interactive.md`
- Create: `apps/docs/content/domains/1_node/16_node-manage/technical/5.2_node-manage_input-mode_non-interactive.md`
- Create: `apps/docs/content/domains/1_node/16_node-manage/technical/6.1_node-manage_output-render_human.md`
- Create: `apps/docs/content/domains/1_node/16_node-manage/technical/6.2_node-manage_output-render_json.md`
- Modify: `apps/docs/content/domains/3_tool/README.md`
- Modify: `apps/docs/content/domains/3_tool/tool-concepts.md`
- Modify: `apps/docs/content/domains/3_tool/tool-doctor.md`
- Modify: `apps/docs/content/domains/3_tool/1_tool-list/tool-list.md`
- Modify: `apps/docs/content/domains/3_tool/1_tool-list/technical/1_tool-list.md`
- Modify: `apps/docs/content/domains/3_tool/2_tool-show/tool-show.md`
- Modify: `apps/docs/content/domains/3_tool/2_tool-show/technical/1_tool-show.md`
- Modify: `apps/docs/content/domains/3_tool/3_tool-install/tool-install.md`
- Modify: `apps/docs/content/domains/3_tool/3_tool-install/technical/1_tool-install.md`
- Modify: `apps/docs/content/domains/3_tool/4_tool-remove/tool-remove.md`
- Modify: `apps/docs/content/domains/3_tool/4_tool-remove/technical/1_tool-remove.md`
- Modify: `apps/docs/content/domains/3_tool/9_tool-update/tool-update.md`
- Modify: `apps/docs/content/domains/3_tool/9_tool-update/technical/1_tool-update.md`
- Modify: `apps/docs/content/domains/3_tool/10_tool-credentials/tool-credentials.md`
- Modify: `apps/docs/content/domains/3_tool/10_tool-credentials/technical/1_tool-credentials.md`
- Modify: `apps/docs/content/domains/3_tool/12_tool-reconfigure/tool-reconfigure.md`
- Modify: `apps/docs/content/domains/3_tool/12_tool-reconfigure/technical/1_tool-reconfigure.md`
- Create: `apps/docs/content/domains/3_tool/catalog/codex-app.md`
- Modify: `apps/docs/content/domains/5_app/README.md`
- Create: `apps/docs/content/domains/5_app/21_app-codex/app-codex.md`
- Create: `apps/docs/content/domains/5_app/21_app-codex/technical/1_app-codex.md`
- Create: `apps/docs/content/domains/5_app/21_app-codex/technical/5.1_app-codex_input-mode_interactive.md`
- Create: `apps/docs/content/domains/5_app/21_app-codex/technical/5.2_app-codex_input-mode_non-interactive.md`
- Create: `apps/docs/content/domains/5_app/21_app-codex/technical/6.1_app-codex_output-render_human.md`
- Create: `apps/docs/content/domains/5_app/21_app-codex/technical/6.2_app-codex_output-render_json.md`
- Modify: `apps/docs/content/domains/authorization-matrix.md`

CLI:

- Modify: `apps/cli/config/commands.php`
- Create: `apps/cli/app/Commands/Node/NodeManageCommand.php`
- Create: `apps/cli/app/Commands/App/AppCodexCommand.php`
- Create: `apps/cli/app/Services/Node/LocalSshAccountResolver.php`
- Create: `apps/cli/app/Services/Node/AuthorizedKeysInstaller.php`
- Create: `apps/cli/app/Services/Platform/LocalPlatformDetector.php`

Gateway API and services:

- Modify: `apps/gateway/routes/api.php`
- Create: `apps/gateway/app/Http/Controllers/Api/NodeManageKeyController.php`
- Create: `apps/gateway/app/Http/Controllers/Api/NodeManageController.php`
- Create: `apps/gateway/app/Http/Requests/Api/ManageNodeApiRequest.php`
- Create: `apps/gateway/app/Services/Nodes/GatewayManagementSshKey.php`
- Create: `apps/gateway/app/Services/Nodes/OperatorNodeManager.php`
- Modify: `apps/gateway/app/Services/Nodes/GatewayNodeCreator.php`
- Create: `apps/gateway/app/Http/Controllers/Api/AppCodexController.php`
- Create: `apps/gateway/app/Http/Requests/Api/AppCodexRequest.php`
- Create: `apps/gateway/app/Services/CodexApp/CodexAppProjectRegistrar.php`
- Create: `apps/gateway/app/Services/CodexApp/CodexAppConfigStore.php`
- Create: `apps/gateway/app/Services/CodexApp/CodexAppConfigMerger.php`
- Create: `apps/gateway/app/Services/CodexApp/CodexAppTargetResolver.php`
- Create: `apps/gateway/app/Tools/CodexAppTool.php`
- Modify: `apps/gateway/app/Providers/AppServiceProvider.php`

Tool eligibility:

- Modify: `apps/gateway/app/Contracts/ToolDefinition.php`
- Modify: `apps/gateway/app/Tools/BaseTool.php`
- Modify: `apps/gateway/app/Services/Tools/ToolCatalog.php`
- Modify: `apps/gateway/app/Services/Tools/ToolRegistry.php`
- Modify: `apps/gateway/app/Services/Tools/ToolRegistryFailure.php`
- Modify: `apps/gateway/app/Services/Tools/ToolInstaller.php`
- Modify: `apps/gateway/app/Services/Tools/ToolRemover.php`
- Modify: `apps/gateway/app/Services/Tools/ToolUpdater.php`
- Modify: `apps/gateway/app/Services/Tools/ToolReconfigurer.php`
- Modify: `apps/gateway/app/Services/Tools/ToolCredentialsReader.php`
- Modify: `apps/gateway/app/Http/Controllers/Api/Concerns/ResolvesVisibleToolNodes.php`
- Modify: tool API controllers that call `authorizedToolTarget()`: install, update, bulk update, show, list, credentials, remove, reconfigure.

Permissions:

- Modify: `apps/gateway/app/Services/Nodes/Access/NodePermissionRegistry.php`
- Modify: `apps/gateway/app/Services/Nodes/Access/NodePermissionPresets.php`

Tests:

- Create: `apps/cli/tests/Feature/Commands/Node/NodeManageCommandTest.php`
- Create: `apps/cli/tests/Feature/Commands/App/AppCodexCommandTest.php`
- Modify: `apps/cli/tests/Feature/CommandListVisibilityTest.php`
- Create: `apps/cli/tests/Feature/Services/Node/AuthorizedKeysInstallerTest.php`
- Create: `apps/cli/tests/Feature/Services/Platform/LocalPlatformDetectorTest.php`
- Create: `apps/gateway/tests/Feature/Http/Api/NodeManageControllerTest.php`
- Create: `apps/gateway/tests/Unit/Services/Nodes/GatewayManagementSshKeyTest.php`
- Create: `apps/gateway/tests/Unit/Services/Nodes/OperatorNodeManagerTest.php`
- Modify: `apps/gateway/tests/Feature/Http/Api/ToolTargetAuthorizationControllerTest.php`
- Modify: `apps/gateway/tests/Feature/Http/Api/ToolListControllerTest.php`
- Modify: `apps/gateway/tests/Feature/Http/Api/ToolShowControllerTest.php`
- Modify: `apps/gateway/tests/Feature/Http/Api/ToolInstallControllerTest.php`
- Modify: `apps/gateway/tests/Feature/Http/Api/ToolRemoveControllerTest.php`
- Modify: `apps/gateway/tests/Feature/Http/Api/ToolUpdateControllerTest.php`
- Modify: `apps/gateway/tests/Feature/Http/Api/ToolCredentialsControllerTest.php`
- Create: `apps/gateway/tests/Unit/Services/Tools/ToolNodeEligibilityTest.php`
- Create: `apps/gateway/tests/Unit/Services/Tools/CodexAppToolTest.php`
- Create: `apps/gateway/tests/Feature/Http/Api/AppCodexControllerTest.php`
- Create: `apps/gateway/tests/Unit/Services/CodexApp/CodexAppConfigMergerTest.php`
- Create: `apps/gateway/tests/Unit/Services/CodexApp/CodexAppProjectRegistrarTest.php`
- Optionally create E2E coverage under `apps/e2e` after retained topology behavior is proven.

## Phase 0: Worktree Setup

- [ ] From the main checkout, prepare the implementation worktree:

```bash
bin/orbit-prepare-worktree managed-operator-codex --with-e2e
```

Expected: worktree exists at `.worktrees/managed-operator-codex`, dependencies are installed, and the bootstrap verification passes.

- [ ] In the worktree, read:

```bash
sed -n '1,260p' AGENTS.md
sed -n '1,320p' docs/superpowers/specs/2026-06-22-managed-operator-codex-design.md
sed -n '1,260p' apps/docs/content/testing/README.md
```

Expected: implementation worker confirms the owned scope stays limited to this plan.

## Phase 1: Product Contracts First

Do not start implementation until this phase is complete and docs lint passes. The new command signatures and the tool target eligibility model are the contract that tests and code must follow.

- [ ] Add product decision lines directly under the newest-first marker in `apps/docs/content/product-decisions.md`:

```markdown
- 2026-06-22 - Codex App project registration is an operator-node tool workflow: roleless active operator nodes opt into gateway SSH through `node:manage`, `codex-app` targets OS-supported non-gateway nodes over WireGuard SSH, and Codex App is not an Agent IDE adapter.
- 2026-06-22 - Tool command targeting is node-wide except the gateway: explicit tool targets may be any active visible non-gateway node, tool definitions declare supported operating systems, and roles may include baseline tools without being the general eligibility gate for user-directed tool commands.
```

- [ ] Add `node:manage` to `apps/docs/content/domains/1_node/README.md` command list and domain rules. State that it is a local opt-in command for active roleless operator identities and that no role or managed flag is stored.

- [ ] Create the `node:manage` public and technical docs listed above. Required contract points:
  - signature: `orbit node:manage [--user=<user>] [--json]`
  - caller: configured client identity only, resolved through WireGuard API identity
  - pre-input prerequisite: caller must be an active roleless node
  - side effects: local `authorized_keys` write, gateway `nodes.user` and `nodes.platform` write, host-key pin using `wireguard_address`, SSH verification
  - failure codes: `validation_failed`, `authorization_failed`, `node.not_operator`, `node.wireguard_address_missing`, `node.management_key_unavailable`, `node.host_key_pin_failed`, ordinary remote shell failure metadata
  - human progress tree phases: resolve caller, install gateway SSH key, detect platform, persist management fields, pin host key, verify SSH
  - JSON success shape under `success.data.management`

- [ ] Add `codex-app` to `apps/docs/content/domains/3_tool/README.md` and create `apps/docs/content/domains/3_tool/catalog/codex-app.md`. Required contract points:
  - category: `operator` as a UX/catalog category, not a node role constraint
  - supported operating systems: `macos`
  - supported node target: any active visible non-gateway node whose `platform` resolves to a supported OS
  - capability surface: Codex App config presence probe plus app-facing add/remove/list through `app:codex`
  - no generic install in v1 unless a later implementation adds a supported installer

- [ ] Update the existing `tool:*` docs listed in the Files section. Required contract points:
  - explicit `--node=<node>` may target any active visible non-gateway node
  - `--app=<app>` remains app-owner resolution and still targets the owning app node
  - target eligibility is active status, caller visibility/permission, gateway exclusion, and the selected tool's supported OS list
  - role baseline tools are still materialized by role provisioning/convergence, but role membership is not the general user-directed `tool:*` eligibility gate
  - unsupported node/OS returns `tool.unsupported_on_node`; unknown, inactive, hidden, or gateway targets keep validation/authorization behavior documented by the command

- [ ] Add `app:codex` to `apps/docs/content/domains/5_app/README.md` and create the app command docs listed above. Required contract points:
  - signatures:

```bash
orbit app:codex add [app] --node=<target-node> [--json]
orbit app:codex remove [app] --node=<target-node> [--json]
orbit app:codex list --node=<target-node> [--json]
```

  - `--node` is always required in non-interactive mode
  - target node must be active, visible to the caller, and not the gateway
  - target node must satisfy the `codex-app` tool's OS support; v1 supports macOS
  - app add/remove resolves the app's owning app node and path
  - first Codex SSH alias is the Orbit app node name
  - config path is fixed to `~/.codex/codex-app/config.json`
  - malformed config fails before writing
  - successful config write plus failed deep-link apply returns success with `success.meta.warnings[]`, not a hidden success

- [ ] Update `apps/docs/content/domains/authorization-matrix.md`:
  - `node:manage`: authenticated caller may manage only itself when active and roleless; no separate grant required.
  - `app:codex`: caller needs `app:codex` on the app-owning node and `app:codex` on the target node. `app:write` and `app:*` imply it.

- [ ] Run docs lint after contract edits:

```bash
composer docs-lint
```

Expected: docs structure and links pass. If docs lint rejects the new command-directory numbers, update the linter or choose the next accepted ordinal before code implementation.

## Phase 2: `node:manage` Tests

- [ ] Write failing CLI tests in `apps/cli/tests/Feature/Commands/Node/NodeManageCommandTest.php`:
  - missing gateway config renders gateway error before local mutation
  - role-bearing caller from `/api/me` fails with `node.not_operator`
  - active roleless caller installs the fetched key once, detects platform, posts `user` and `platform`, and renders success
  - `--json` renders the gateway success envelope

Use `fakeGateway()` and `Http::fake()` from `apps/cli/tests/Pest.php`; put `HOME` under a test temp path and assert `~/.ssh/authorized_keys` content.

- [ ] Write failing CLI service tests:

```bash
bin/orbit-cli-pest --compact tests/Feature/Services/Node/AuthorizedKeysInstallerTest.php
bin/orbit-cli-pest --compact tests/Feature/Services/Platform/LocalPlatformDetectorTest.php
```

Expected first run: FAIL because services do not exist.

- [ ] Write failing gateway API tests in `apps/gateway/tests/Feature/Http/Api/NodeManageControllerTest.php`:
  - `GET /api/nodes/self/manage-key` returns the gateway SSH public key for an active roleless caller
  - `POST /api/nodes/self/manage` rejects a role-bearing caller
  - rejects missing `wireguard_address`
  - persists `user` and `platform`
  - pins host key by the node's `wireguard_address`, not `host`
  - verifies SSH through `RemoteShell`
  - returns ordinary remote shell failure metadata when verification fails

Use `REMOTE_ADDR` to authenticate the caller, `Process::fake()` for host-key scans and gateway public-key derivation, and a recording `RemoteShell` fake.

- [ ] Run the focused failing tests and record the expected failures:

```bash
bin/orbit-cli-pest --compact tests/Feature/Commands/Node/NodeManageCommandTest.php
bin/orbit-gateway-pest --compact tests/Feature/Http/Api/NodeManageControllerTest.php
```

Expected: missing classes/routes/commands.

## Phase 3: Implement `node:manage`

- [ ] Extract gateway SSH public-key derivation:
  - Create `apps/gateway/app/Services/Nodes/GatewayManagementSshKey.php`.
  - Move the `ssh-keygen -y -f ~/.ssh/id_ed25519` behavior out of `GatewayNodeCreator::gatewaySshPublicKey()`.
  - Keep the same failure meaning, but expose a throwable/service result usable by controllers.
  - Replace `GatewayNodeCreator::gatewaySshPublicKey()` internals with the new service.

- [ ] Add gateway API:
  - `NodeManageKeyController` returns:

```json
{
  "success": {
    "data": {
      "management_ssh_key": {
        "public_key": "ssh-ed25519 ..."
      }
    }
  }
}
```

  - `ManageNodeApiRequest` accepts `user` and `platform`, both required strings.
  - `NodeManageController` validates caller active/roleless, validates non-empty `wireguard_address`, updates `user` and `platform`, pins host key with `SshHostKeyPinner::pin($caller->wireguard_address)`, persists via `SshHostKeyPinner::persist()`, then verifies `RemoteShell->run($caller->refresh(), 'true', ['throw' => false, 'timeout' => 30])`.
  - Register:

```php
Route::get('/nodes/self/manage-key', NodeManageKeyController::class);
Route::post('/nodes/self/manage', NodeManageController::class);
```

Place both inside the existing WireGuard-authenticated API group.

- [ ] Add CLI services:
  - `LocalSshAccountResolver` resolves `--user` or the current user and home directory.
  - `AuthorizedKeysInstaller` creates `~/.ssh`, writes `authorized_keys` mode `0600`, appends the exact gateway public key idempotently, and never removes existing keys.
  - `LocalPlatformDetector` returns `macos_<version>` on Darwin and `<id>_<version>` on Linux, matching gateway detector format.

- [ ] Add `NodeManageCommand`:
  - extend `GatewayCommand`
  - signature `node:manage {--user=} {--json}`
  - `GET /api/me`, reject non-active or active-role caller before local writes
  - `GET /api/nodes/self/manage-key`
  - install authorized key locally
  - detect platform
  - `POST /api/nodes/self/manage` with `user` and `platform`
  - render JSON envelope when `--json`; otherwise render the documented progress tree and summary

- [ ] Register `NodeManageCommand` in `apps/cli/config/commands.php` and update command-list expected output.

- [ ] Run focused tests:

```bash
bin/orbit-cli-pest --compact tests/Feature/Commands/Node/NodeManageCommandTest.php
bin/orbit-cli-pest --compact tests/Feature/Services/Node/AuthorizedKeysInstallerTest.php
bin/orbit-cli-pest --compact tests/Feature/Services/Platform/LocalPlatformDetectorTest.php
bin/orbit-gateway-pest --compact tests/Feature/Http/Api/NodeManageControllerTest.php
bin/orbit-gateway-pest --compact tests/Unit/Services/Nodes/GatewayManagementSshKeyTest.php
```

Expected: PASS.

## Phase 4: Tool Target And OS Eligibility

- [ ] Write failing tests:
  - `ToolTargetAuthorizationControllerTest`: explicit `node=operator-mac` is accepted as a visible active non-gateway node when authorized and rejected as `authorization_failed` when hidden.
  - `ToolTargetAuthorizationControllerTest`: explicit `node=<role-bearing-non-gateway>` is accepted before tool-specific OS eligibility is checked.
  - `ToolTargetAuthorizationControllerTest`: explicit `node=<gateway>` is rejected because tools are not installed on the gateway.
  - `ToolInstallControllerTest`: installing a Linux-only tool on a macOS node fails with `tool.unsupported_on_node`, not "Expected a visible tool node name."
  - `ToolInstallControllerTest`: role-bearing nodes are not rejected merely because the selected tool previously declared `requiredNodeRole()`.
  - `ToolNodeEligibilityTest`: `codex-app` supports active visible non-gateway macOS nodes and rejects gateway nodes, inactive nodes, hidden nodes, and non-macOS platforms.
  - `ToolCatalogTest`: role baseline metadata, if retained, is exposed separately from user-directed tool eligibility.

- [ ] Extend tool definitions:
  - Replace `requiredNodeRole()` as a user-directed eligibility gate with operating-system support methods, such as:

```php
/**
 * @return list<string>
 */
public function supportedOperatingSystems(): array;
```

  - Use OS family values resolved from `nodes.platform`, such as `linux` and `macos`.
  - `BaseTool::supportedOperatingSystems()` returns the current default for existing host/server tools, expected to be `['linux']` unless the implementation audit finds a broader existing platform contract.
  - Existing tools that are known Linux/systemd/apt/Docker-host tools explicitly or by base-class inheritance support `linux`.
  - `codex-app` supports `macos`.
  - If role baseline metadata is still needed for provisioning docs or convergence, rename it to a non-gating concept such as `includedInRoles()`/`baselineRoles()` or keep it private to role baseline services. Do not use `requiredNodeRole()` to decide whether a user can install, update, remove, show, reconfigure, or inspect a tool on an explicit node.
  - Add `ToolCatalog::supportsNode(string $tool, Node $node): bool` or a richer result object that reports the unsupported reason.
  - Add `ToolRegistryFailure::unsupportedOnNode()` returning `tool.unsupported_on_node`.

- [ ] Change tool target resolution:
  - In `ResolvesVisibleToolNodes`, replace "visible tool node" as the explicit `--node` target set with "visible active non-gateway node."
  - Gateway callers may target active non-gateway nodes, but never the gateway itself.
  - Non-gateway callers may target active non-gateway nodes allowed by `NodeAccessAuthorizer` for the command permission.
  - App selectors remain app-host only because `--app` resolves the app's owning node.
  - Preserve authorization failures for existing but hidden targets.
  - Update validation copy from "Expected a visible tool node name" to "Expected a visible non-gateway node name" where this path is used.
  - Review implicit/default target selection against the updated docs. If the command auto-selects a node, it must not select the gateway and must still apply tool OS support before mutating state. Do not keep role-host discovery as a hidden eligibility gate.

- [ ] Change gateway tool services:
  - `ToolRegistry::resolveNode()` accepts any active non-gateway explicit node for validation/show/list filtering.
  - `ToolRegistry::list()` may list existing tool rows for any active non-gateway node visible to the caller; it must not hide a row solely because the node lacks a role in `activeToolHostNodeIds()`.
  - `ToolInstaller`, `ToolUpdater`, `ToolRemover`, `ToolReconfigurer`, `ToolCredentialsReader`, live show/probe services, and related controllers call `ToolCatalog::supportsNode()` after resolving the target node and before row writes, remote shell, credentials reads, or reconfigure/update/remove actions.
  - Remove `ToolInstallController`'s `allowAnyActiveNode = requiredNodeRole() !== null` behavior. Any explicit tool target should use the active visible non-gateway path, then tool OS support.
  - Remove or stop using `ToolRegistryFailure::nodeRoleRequired()` for user-directed tool commands. Replace role failures with `tool.unsupported_on_node` only when the selected tool's OS support or gateway exclusion rejects the target.

- [ ] Run focused tests:

```bash
bin/orbit-gateway-pest --compact tests/Feature/Http/Api/ToolTargetAuthorizationControllerTest.php
bin/orbit-gateway-pest --compact tests/Feature/Http/Api/ToolInstallControllerTest.php
bin/orbit-gateway-pest --compact tests/Unit/Services/Tools/ToolNodeEligibilityTest.php
```

Expected: PASS.

## Phase 5: `codex-app` Tool

- [ ] Add `apps/gateway/app/Tools/CodexAppTool.php`:
  - `slug()` returns `codex-app`
  - `category()` returns `operator` as catalog grouping only
  - `capabilities()` returns an empty list or `['probe']` only if existing probe code recognizes it
  - `supportedOperatingSystems()` returns `['macos']`
  - `probeMetadata()` returns fixed metadata for `~/.codex/codex-app/config.json` without making it an arbitrary file-management API

- [ ] Register it in `AppServiceProvider`'s `ToolDefinitionRegistry`.

- [ ] Add tests:

```bash
bin/orbit-gateway-pest --compact tests/Unit/Services/Tools/CodexAppToolTest.php
bin/orbit-gateway-pest --compact tests/Unit/Services/Tools/ToolCatalogTest.php
```

Expected: PASS and catalog includes `codex-app`.

## Phase 6: Codex App Config Services

- [ ] Write failing `CodexAppConfigMergerTest` cases:
  - empty config becomes `{"version":1,"remoteConnections":[]}` plus the added project
  - adding a new project creates the connection for `sshAlias`
  - adding the same `remotePath` updates/keeps the label idempotently
  - unrelated connections and projects are preserved
  - remove deletes the exact project path
  - remove drops an empty connection only if the command contract says it should; use "drop empty connection" in this implementation
  - malformed JSON fails before write

- [ ] Implement `CodexAppConfigMerger` as a pure PHP service. It must accept decoded arrays and return normalized arrays. Do not read or write files in this class.

- [ ] Implement `CodexAppConfigStore` around `RemoteShell`:
  - fixed config path: `~/.codex/codex-app/config.json`
  - read: `test -f "$path" && cat "$path" || true`
  - write: `mkdir -p ~/.codex/codex-app`, write to a temp file with mode `0600`, then `mv` atomically
  - apply: `open codex://codex-app/apply-config`
  - no path option, no arbitrary read/write/delete

- [ ] Implement `CodexAppTargetResolver`:
  - resolve app by name/domain using existing app patterns
  - require app owning node active and app-host role
  - resolve target node by `--node`
  - require active, visible, non-gateway
  - require `ToolCatalog::supportsNode('codex-app', $targetNode)`; v1 supports macOS
  - require non-empty `wireguard_address`
  - use Orbit node name as the first Codex `sshAlias`

- [ ] Implement `CodexAppProjectRegistrar`:
  - `add(app, targetNode)` reads config, merges project, writes config, applies deep link
  - `remove(app, targetNode)` reads config, removes project, writes config, applies deep link
  - `list(targetNode)` reads config and returns remote connections/projects without applying deep link
  - if write succeeds but apply fails, return success data plus warning `codex_app.apply_failed`

- [ ] Run focused unit tests:

```bash
bin/orbit-gateway-pest --compact tests/Unit/Services/CodexApp/CodexAppConfigMergerTest.php
bin/orbit-gateway-pest --compact tests/Unit/Services/CodexApp/CodexAppProjectRegistrarTest.php
```

Expected: PASS.

## Phase 7: `app:codex` API And CLI

- [ ] Add `app:codex` permission:
  - Add `app:codex` to `NodePermissionRegistry`.
  - Add implication from `app:write` to `app:codex`.
  - Ensure `app:*` and `*` cover it through existing wildcard behavior.
  - Keep `operator` preset unchanged unless docs explicitly grant this workflow to that preset.

- [ ] Add `AppCodexController` with routes:

```php
Route::get('/apps/codex/projects', [AppCodexController::class, 'list']);
Route::post('/apps/{app}/codex', [AppCodexController::class, 'add']);
Route::delete('/apps/{app}/codex', [AppCodexController::class, 'remove']);
```

Register the static `/apps/codex/projects` route before `/apps/{app}` routes.

- [ ] In `AppCodexController`, authorize manually with `NodeAccessAuthorizer`:
  - caller must have `app:codex` on the app-owning node for add/remove
  - caller must have `app:codex` on the target node for add/remove/list
  - gateway callers with gateway role retain super-admin behavior through existing authorizer semantics

- [ ] Return JSON shapes:

```json
{
  "success": {
    "data": {
      "codex_project": {
        "action": "added",
        "app": "hauser-design",
        "target_node": "nicks-mac",
        "ssh_alias": "beast",
        "remote_path": "/home/nckrtl/apps/hauser-design",
        "label": "hauser-design",
        "config_path": "~/.codex/codex-app/config.json",
        "applied": true
      }
    }
  }
}
```

List returns `success.data.remote_connections`.

- [ ] Add `AppCodexCommand`:
  - signature `app:codex {action? : add, remove, or list} {app? : App name or hostname} {--node=} {--json}`
  - non-interactive mode requires action; add/remove require app and node; list requires node
  - interactive mode may prompt for missing action/app/node using existing prompt style
  - call the API routes above
  - render a progress tree for add/remove and compact list output for list

- [ ] Register `AppCodexCommand` in `apps/cli/config/commands.php`.

- [ ] Add CLI tests:

```bash
bin/orbit-cli-pest --compact tests/Feature/Commands/App/AppCodexCommandTest.php
```

Expected: PASS.

- [ ] Add gateway API tests:

```bash
bin/orbit-gateway-pest --compact tests/Feature/Http/Api/AppCodexControllerTest.php
```

Expected: PASS.

## Phase 8: Focused Verification And Formatting

- [ ] Run docs lint:

```bash
composer docs-lint
```

- [ ] Run focused CLI tests:

```bash
bin/orbit-cli-pest --compact tests/Feature/Commands/Node/NodeManageCommandTest.php
bin/orbit-cli-pest --compact tests/Feature/Commands/App/AppCodexCommandTest.php
bin/orbit-cli-pest --compact tests/Feature/CommandListVisibilityTest.php
```

- [ ] Run focused gateway tests:

```bash
bin/orbit-gateway-pest --compact tests/Feature/Http/Api/NodeManageControllerTest.php
bin/orbit-gateway-pest --compact tests/Feature/Http/Api/AppCodexControllerTest.php
bin/orbit-gateway-pest --compact tests/Feature/Http/Api/ToolTargetAuthorizationControllerTest.php
bin/orbit-gateway-pest --compact tests/Feature/Http/Api/ToolInstallControllerTest.php
bin/orbit-gateway-pest --compact tests/Unit/Services/CodexApp/CodexAppConfigMergerTest.php
bin/orbit-gateway-pest --compact tests/Unit/Services/Tools/ToolNodeEligibilityTest.php
```

- [ ] Format PHP:

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

- [ ] Run broad quality gate before handoff:

```bash
composer quality-check
```

Expected: PASS.

## Phase 9: Retained Topology Gate And E2E

Because this changes public CLI commands, node SSH behavior, and remote operator-node behavior, run the retained CLI/Incus gate before durable E2E.

- [ ] Acquire a retained Incus topology from the implementation worktree:

```bash
composer e2e:incus -- --start \
  --topology=operator_gateway_app-dev_app-prod_ingress \
  --checkout-roles=operator,gateway \
  --json
```

- [ ] In the retained operator VM, run:

```bash
cd /home/orbit/orbit-run
orbit node:manage --json
orbit app:codex list --node=<operator-node> --json
```

Expected: `node:manage` validates the operator opt-in path. If the retained Linux operator cannot represent macOS Codex App behavior, `app:codex list` should fail with the documented OS-support error; record that limitation and use the retained lane only for command/API path validation.

- [ ] If a live Mac operator lane is available through the existing retained/live topology support, run:

```bash
orbit node:manage --json
orbit app:codex add <test-app> --node=<mac-operator> --json
orbit app:codex list --node=<mac-operator> --json
orbit app:codex remove <test-app> --node=<mac-operator> --json
```

Expected: config file changes under `~/.codex/codex-app/config.json` and gateway reaches the Mac operator over WireGuard SSH. Do not use standing live infrastructure as the durable test lane.

- [ ] Release the retained topology:

```bash
composer e2e:incus -- --stop --id=<id> --json
```

- [ ] Add or adjust durable E2E only for behavior that can be represented in prepared topology without a live Codex App dependency:
  - `node:manage` command/API path
  - explicit active non-gateway node targeting failure/success contract
  - `app:codex` config merge with fake apply command, if the harness has a target-node fixture that can safely write a temp HOME

- [ ] Run the relevant durable lane:

```bash
composer test:e2e:incus
```

Expected: PASS for added prepared-topology coverage.

## Self-Review Checklist

- [ ] No role, managed flag, or transport object added.
- [ ] `node:manage` uses existing `nodes.user`, `nodes.platform`, `nodes.wireguard_address`, and host-key fields.
- [ ] Gateway SSH uses `wireguard_address`; no public/local hostname is required for operator SSH.
- [ ] Explicit `--node=<node>` works for active visible non-gateway nodes.
- [ ] The gateway is never eligible for tool install/update/remove/reconfigure/credentials/probe actions.
- [ ] Tool eligibility is defined by tool OS support, not by node role. Roles may include baseline tool intent only.
- [ ] Existing Linux tools do not silently become supported on macOS nodes.
- [ ] `codex-app` is a tool/catalog entry, not an Agent IDE adapter.
- [ ] `app:codex` edits only `~/.codex/codex-app/config.json`.
- [ ] Malformed Codex config is preserved.
- [ ] Deep-link apply partial success is visible as a structured warning.
- [ ] Docs, tests, implementation, and project skill guidance are aligned.

## Open Questions

None blocking. The plan chooses two concrete defaults not fully specified by the design:

- `app:codex` uses a new `app:codex` permission, implied by `app:write`, and requires it on both the app-owning node and target node.
- A successful config write with failed deep-link apply returns success with `success.meta.warnings[]` instead of failing the whole operation.

## Future Workspace And Worktree Compatibility

Codex App's documented model is project-first: a saved project is a directory, local threads run in that project, worktree threads are created by Codex from that project, and permanent worktrees may become their own app projects. Orbit's `sourceType` is therefore an Orbit-only adapter concept, not a Codex App config field.

Keep the v1 implementation from baking app-only assumptions into the shared Codex services:

- Model Codex config entries internally as a project registration descriptor with `label`, `sshAlias`, `remotePath`, `targetNode`, and Orbit-only `sourceType`, even if v1 only passes `sourceType=app`.
- Do not write `sourceType` into Codex App config unless a verified Codex schema supports equivalent metadata.
- Keep `CodexAppConfigMerger` path-based and source-agnostic; it should not know about app or workspace models.
- Keep app resolution in `CodexAppTargetResolver`/controller code, not in the config merge/write layer.
- Do not add workspace routes, commands, arbitrary path registration, or Codex-managed worktree registration in this scope.
- A future `workspace:codex` should register an Orbit workspace as a saved Codex project only when that workspace has a stable remote path worth opening directly in Codex. It should not try to mirror every Codex-created managed worktree.
- Before implementing workspace support later, verify Codex App's current config schema and worktree behavior against official Codex docs.
