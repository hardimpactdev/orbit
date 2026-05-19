# Agent Node Role Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `agent` as an isolated hosted node role that can run first-party autonomous agent tools through Orbit-managed tooling, while tightening node access into explicit scoped permissions.

**Architecture:** `agent` is a hosted role assignment selected only during `node:new`; it is exclusive with every other hosted role and uses the normal gateway, WireGuard, DNS, proxy, tool, doctor, and activity model. `node_access` becomes the first authorization gate and stores normalized permission strings that decide what the consuming node may do on the serving node. OpenClaw and Hermes are ordinary managed tools in the `tool` family, eligible only on active `agent` nodes.

**Tech Stack:** Laravel 13, PHP 8.5, SQLite migrations, Pest 4, Laravel Prompts, WireGuard identity, Caddy internal HTTPS routes, Supervisor-managed tool runtimes, existing `RemoteShell` and tool doctor contracts.

---

## Product Decisions

- `agent` is a hosted role assignment, not a separate node type.
- `agent` is exclusive with `gateway`, `app-development`, `app-production`, `database`, and future hosted workload roles unless explicitly redesigned.
- `agent` can only be selected during `node:new`; `node role:add agent` is rejected because adding it to an existing node bypasses the isolation model.
- `agent` has role settings with `tld`, defaulting to `agent`.
- The agent TLD uses the same gateway-owned development DNS mapping pattern as `app-development`: `*.agent` resolves to the agent node's WireGuard address.
- The `agent` role baseline includes Caddy, Supervisor, WireGuard/node identity/trust material, and an unprivileged shared `orbit-agent` runtime user.
- Agent tools do not run as the privileged `orbit` maintenance/apply user.
- OpenClaw and Hermes are normal `tool` catalog entries with category `agent`, not a separate state family.
- OpenClaw and Hermes may both be installed and running on the same agent node.
- There is no default agent tool. During `node:new --role=agent`, Orbit may offer optional agent tool selection, but an agent node can also be created without installing OpenClaw or Hermes.
- When a human starts or installs a second running agent tool on the same node, Orbit warns that multiple running agent tools weaken node-level traceability and asks for yes/no confirmation.
- Machine-readable/non-interactive flows do not prompt; they return structured warnings and proceed when input is otherwise valid.
- Activity remains node-level. Orbit does not add per-agent-tool sub-identities because those would be spoofable unless a stronger identity mechanism is built.
- Agent tool web UIs are exposed by default through tool-owned internal HTTPS proxy routes under the agent TLD, e.g. `https://openclaw.agent` and `https://hermes.agent`.
- Agent tool credentials and web UI tokens are exposed through `tool:credentials` only when the caller has the explicit credentials permission.
- Agent self grants do not include `tool:credentials`.
- `node_access` becomes scoped with permissions stored on the grant row.
- Grants are the first gate. Without a grant edge, a node cannot access another node.
- Permissions are the second gate. They decide what the consuming node may do to the serving node.
- `node:grant` creates the access edge with initial permissions. It does not become the long-term permission editing surface.
- `node:permissions` owns viewing, updating, and upserting the permission set for a consuming node and serving node.
- `node:permissions` may create a missing grant edge when the caller is a gateway-admin and submits a valid non-empty permission set through interactive selection, `--preset`, `--permissions`, or `--add`.
- Read-only `node:permissions` and removal-only permission changes still require an existing grant edge.
- `node:permissions` is gateway-admin only: the caller must have a grant to the gateway with `*`.
- Explicit self-grants are required for self-access; self-access is not implicit.
- Permissions support implication and normalization. Redundant permissions are not stored.
- Wildcard permissions such as `node:*` and `*` are dynamic and include future permissions in that namespace. Presets do not use wildcards.
- A grant to the gateway with `*` or the `gateway-admin` preset is special: it means fleet-wide super-admin visibility and access, including current and future nodes.
- `node:new` requires authorization against the gateway with `node:new` or `*`; the `gateway-admin` preset is the normal way to grant that authority.
- Agent setup does not offer `gateway-admin` by default.
- `node:new` grant setup asks only when needed:
  - "Does this node need access to other nodes?" default `no`.
  - "Should other nodes need access to this node?" default `no`.
  - When either answer is `yes`, the user selects nodes and permissions per selected node.
- The grant target selector includes `all`, meaning all current eligible nodes only.
- Self-grant setup offers `default` and `custom`.
- A role-default self grant is the union of defaults for every hosted role on the node. Permission conflicts indicate role incompatibility, not deny rules.
- Agent default self grant allows `node:read`, `tool:read`, `tool:restart`, `tool:update:agent-tools`, and `doctor:verify`.
- Agent default self grant denies `node:update`, `tool:credentials`, `tool:install`, `tool:remove`, `tool:stop`, `tool:reconfigure`, firewall writes, grant writes, node role writes, VPN writes, `doctor:restore`, and `doctor:adopt`.
- Agent default cross-node grant preset is `operator`.
- `operator` can read firewall rules and report firewall doctor findings, but cannot create, update, or remove firewall rules.
- Agent self-update is limited to agent tools only, not baseline tools such as Caddy, Supervisor, WireGuard, PHP CLI, Composer, or Orbit itself.

## Current Evidence

- `docs/architecture.md` and `docs/tech-stack.md` already mention `agent` as a hosted role in design.
- `docs/domains/1_node/**` still formalizes only `app-development`, `app-production`, and `database`, so node docs must be aligned before implementation.
- Current `node_access` is binary (`consumer_node_id`, `serving_node_id`) and self-grants are rejected by `NodeGrantController`.
- Old Orbit evidence favors gateway-owned state plus agentless hosted nodes. The new `agent` role must remain a workload calling the gateway API, not an Orbit control-plane daemon.
- OpenClaw docs currently document Linux installation with `curl -fsSL https://openclaw.ai/install.sh | bash`, verification with `openclaw --version`, `openclaw doctor`, and `openclaw gateway status`.
- Hermes docs currently document Linux installation with `curl -fsSL https://raw.githubusercontent.com/NousResearch/hermes-agent/main/scripts/install.sh | bash`, verification with `hermes doctor`, and update with `hermes update`.

## Scope Boundaries

In scope:

- Product docs and command contracts for `agent`, scoped grants, permissions, and agent tools.
- Schema changes for `node_access.permissions`.
- Code-defined permission registry, presets, normalization, and authorization service.
- `agent` role settings and baseline.
- `node:new` UX for agent role selection, TLD, self grants, optional grant setup, and optional agent tool installation.
- Tool catalog entries and docs for OpenClaw and Hermes.
- Tool doctor/runtime support needed to verify installed OpenClaw/Hermes versions, service status, config, credentials metadata, and internal proxy routes.
- Focused feature tests and narrow E2E smoke tests.

Out of scope:

- Per-agent-tool Orbit identity or per-tool grants.
- Making OpenClaw/Hermes update or install scripts resilient to every upstream installer failure mode.
- Public internet exposure for agent web UIs.
- Future-node auto-grant policies other than gateway-admin wildcard behavior.
- A UI for editing permission sets.
- A new `agent_tool` state family.

## File Map

### Product Docs

- Modify: `docs/architecture.md` — make the `agent` hosted role official, describe exclusivity, node-level traceability, and scoped grants.
- Modify: `docs/tech-stack.md` — document the `agent` role baseline, `orbit-agent` runtime user, Caddy UI routes, and OpenClaw/Hermes tool runtime.
- Modify: `docs/concepts.md` — add indexed concepts for `Agent hosted role`, `Node access permission`, `Permission preset`, `Gateway-admin grant`, and agent tools.
- Modify: `docs/domains/1_node/README.md` — update role model, compatibility matrix, grant model, and `node:new` grant setup behavior.
- Modify: `docs/domains/1_node/node-concepts.md` — add `agent`, TLD settings, role exclusivity, scoped permissions, explicit self-grants, gateway-admin semantics.
- Modify: `docs/domains/1_node/node-doctor.md` — add agent role readiness checks, agent TLD mapping checks, scoped grant validity checks.
- Modify: `docs/domains/1_node/1_node-new/node-new.md` and technical docs — document agent-only-at-creation behavior, grant prompts, self-grant presets, optional tool installation.
- Modify: `docs/domains/1_node/5_node-grant/**` — document permission presets, custom permissions, self-grants, wildcard behavior, gateway-admin confirmation.
- Modify: `docs/domains/1_node/6_node-revoke/**` — document revoking self-grants and gateway-admin grants.
- Create: `docs/domains/1_node/15_node-permissions/node-permissions.md` and technical docs — document viewing and updating grant permissions after provisioning.
- Modify: `docs/domains/1_node/12_node-role-add/**` — document `agent` rejection.
- Modify: `docs/domains/1_node/13_node-role-update/**` — document that agent role setting changes require `node:update` permission; agent self grants do not include that permission.
- Modify: `docs/domains/3_tool/README.md` — add `openclaw` and `hermes` to the catalog table.
- Create: `docs/domains/3_tool/catalog/openclaw.md` — tool contract, install/update/doctor/credentials/proxy behavior.
- Create: `docs/domains/3_tool/catalog/hermes.md` — tool contract, install/update/doctor/credentials/proxy behavior.
- Modify: `docs/domains/3_tool/tool-doctor.md` — add agent tool probe/fix expectations.
- Modify: `docs/domains/4_firewall/README.md` and `firewall-concepts.md` — clarify `operator` may read/report firewall but not write firewall by default; add `agent` as an eligible read target where relevant.
- Modify: `docs/domains/11_operation/operation-concepts.md` — document doctor permissions (`doctor:verify`, `doctor:restore`, `doctor:adopt`) and that `operator` excludes firewall writes, restore, and adopt by default.
- Modify: `docs/domains/17_activity/activity-concepts.md` — state that autonomous agent activity is attributed to the node identity only.

### Permission System

- Create: `database/migrations/2026_05_19_000000_add_permissions_to_node_access_table.php` — add nullable JSON `permissions` with backfill.
- Modify: `app/Models/NodeAccess.php` — add `permissions` fillable/cast.
- Create: `app/Services/Nodes/Access/NodePermissionRegistry.php` — code-defined permission names, implications, dynamic wildcard matching, labels, descriptions.
- Create: `app/Services/Nodes/Access/NodePermissionNormalizer.php` — remove redundancies and validate unknown permission strings.
- Create: `app/Services/Nodes/Access/NodePermissionPresets.php` — expand `default`, `operator`, `read-only`, `developer`, `admin`, `gateway-admin`, role self presets.
- Create: `app/Services/Nodes/Access/NodeAccessAuthorizer.php` — resolve grant edges, gateway-admin semantics, self-grants, and permission checks.
- Create: `app/Data/Nodes/NodeAccessPermissions.php` — small value object for normalized permission lists and warnings.
- Modify: controllers that query `node_access` directly — route checks through `NodeAccessAuthorizer` instead of ad hoc edge checks.
- Modify: `tests/Pest.php` and E2E support helpers — write grants with explicit permissions.

### Agent Role

- Modify: `app/Enums/Nodes/NodeRoleName.php` — add `Agent = 'agent'`.
- Create: `app/Data/Nodes/RoleSettings/AgentRoleSettings.php` — validate and store `tld`, defaulting to `agent` when node creation omits it in interactive setup.
- Modify: `app/Services/Nodes/Roles/NodeRoleDefinition.php` — split assignability so a role can be allowed in `node:new` but rejected by `node role:add`.
- Modify: `app/Services/Nodes/Roles/NodeRoleRegistry.php` — register `agent`, exclusive conflicts, Ubuntu support, settings class, creation-only assignability.
- Modify: `app/Services/Nodes/Roles/NodeRoleAssignments.php` — add agent helpers and include agent in managed tool host eligibility where appropriate.
- Modify: `app/Services/Nodes/Roles/NodeRoleAssignmentService.php` — support agent TLD uniqueness, generalize DNS mapping cleanup from app-development to TLD-backed roles, sync legacy node fields for `agent`.
- Create: `app/Services/Nodes/Roles/RoleBaselines/AgentRoleBaseline.php` — converge Caddy, Supervisor, shared `orbit-agent` user, and agent TLD DNS mapping.
- Modify: `app/Services/Nodes/Roles/NodeRoleBaselineConverger.php` — wire `AgentRoleBaseline`.
- Modify: `app/Services/Nodes/DevelopmentDnsMappingEnactor.php` — generalize naming from development-only where needed, or add explicit agent-role entry points that reuse the same backend.
- Modify: `app/Actions/Nodes/ReenactNodeArtifacts.php` and node doctor services — reapply agent role baseline and TLD mapping.

### Node Commands And API

- Modify: `app/Console/Commands/NodeNewCommand.php` — add agent role input, agent TLD default, self-grant default/custom prompt, optional cross-grant prompts, optional agent tool selection/install.
- Modify: `app/Http/Gateway/Requests/Nodes/CreateNodeRequest.php` and response DTOs — carry role settings, self-grant mode, grant setup payloads, selected agent tools.
- Modify: `app/Http/Controllers/Api/NodeStoreController.php` — authorize through `NodeAccessAuthorizer`, create self grant, optional cross grants, and optional tool installs after role convergence.
- Modify: `app/Console/Commands/NodeGrantCommand.php` and `NodeRevokeCommand.php` — accept presets/custom permissions, allow self-grants, show redundant-permission warnings, confirm gateway-admin.
- Create: `app/Console/Commands/NodePermissionsCommand.php` — show, update, and upsert permissions for node grants.
- Modify: `app/Http/Gateway/Requests/Nodes/GrantNodeRequest.php` and response DTOs — include permissions and preset.
- Create: `app/Http/Gateway/Requests/Nodes/UpdateNodePermissionsRequest.php` and response DTO — carry preset, comma-separated permissions, add/remove operations, and normalized output.
- Modify: `app/Http/Controllers/Api/NodeGrantController.php` — remove self-grant rejection, normalize permissions, enforce gateway-admin confirmation semantics.
- Create: `app/Http/Controllers/Api/NodePermissionsController.php` — gateway-admin-only read/update/upsert endpoint for grant permissions.
- Modify: `app/Http/Controllers/Api/NodeRevokeController.php` — report self-lockout and gateway-admin lockout accurately.
- Modify: `app/Console/Commands/NodeRoleAddCommand.php` and `NodeRoleAddController.php` — reject `agent` with a specific error.
- Modify: `app/Console/Commands/NodeShowCommand.php` and `NodeShowController.php` — render permissions on consuming/serving grants.
- Modify: `app/Http/Controllers/Api/MeController.php` — include permission-aware identity context if command clients need it for prompts.

### Agent Tools

- Extend: `app/Contracts/ToolDefinition.php` and `app/Services/Tools/ToolCatalog.php` — add category metadata and agent-tool classification, or expose it through probe metadata if that is less invasive.
- Create: `app/Tools/OpenClawTool.php` — install, update, credentials, doctor probe metadata, service metadata.
- Create: `app/Tools/HermesTool.php` — install, update, credentials, doctor probe metadata, service metadata.
- Modify: `app/Providers/AppServiceProvider.php` — register OpenClaw and Hermes definitions.
- Modify: `app/Services/Tools/ToolInstaller.php` — install agent tools as `orbit-agent`, create tool-owned internal proxy routes, store credentials metadata, and warn for multiple running agent tools.
- Modify: `app/Services/Tools/ToolUpdater.php` — enforce `tool:update:agent-tools` for agent self-update and prevent baseline tool self-update.
- Modify: `app/Services/Tools/ToolLifecycleManager.php` — enforce restart-only self lifecycle for agent self-grants.
- Modify: `app/Services/Tools/ToolCredentialsReader.php` — require `tool:credentials` and do not include credentials in `tool:read`.
- Modify: `app/Services/Tools/ToolsProbe.php` and `ToolsFixer.php` — add agent tool version/config/lifecycle/credentials checks.
- Modify: proxy services that create tool-owned routes — support `openclaw.<agent-tld>` and `hermes.<agent-tld>` internal routes.

### Tests

- Create/modify docs tests as needed for concept index and command contract changes.
- Create: `tests/Unit/Services/Nodes/Access/NodePermissionRegistryTest.php`
- Create: `tests/Unit/Services/Nodes/Access/NodePermissionNormalizerTest.php`
- Create: `tests/Unit/Services/Nodes/Access/NodePermissionPresetsTest.php`
- Create: `tests/Unit/Services/Nodes/Access/NodeAccessAuthorizerTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeGrantCommandTest.php`
- Create: `tests/Feature/Commands/Nodes/NodePermissionsCommandTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeRevokeCommandTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeNewCommandTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeRoleUpdateCommandTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeShowCommandTest.php`
- Create: `tests/Feature/Commands/Nodes/AgentNodeNewCommandTest.php`
- Create: `tests/Feature/Services/Nodes/Roles/AgentRoleBaselineTest.php`
- Create: `tests/Feature/Commands/Tools/AgentToolInstallCommandTest.php`
- Create: `tests/Feature/Commands/Tools/AgentToolAuthorizationTest.php`
- Modify: `tests/Feature/Commands/Tools/ToolCredentialsCommandTest.php`
- Modify: `tests/Feature/Commands/Tools/ToolUpdateCommandTest.php`
- Modify: `tests/Feature/Commands/Operations/DoctorCommandContractTest.php`
- Create: `tests/E2E/Ephemeral/AgentNodeProvisioningTest.php` — optional first live lane once feature tests pass.

---

## Implementation Tasks

### Task 1: Align Product Documentation

**Files:**
- Modify docs listed under "Product Docs"

- [ ] **Step 1: Update architecture and tech stack**

Add `agent` as a formal hosted role. Include these exact contract points:

```markdown
- `agent` is an exclusive hosted role selected only during `node:new`.
- Agent nodes run autonomous agent tools as a workload, not an Orbit control plane.
- Agent nodes authenticate as nodes over WireGuard and are authorized through scoped `node_access` grants.
- Agent activity is attributed to the node identity; Orbit does not claim per-tool attribution.
- Agent tool UIs are internal HTTPS routes under the agent role TLD.
```

- [ ] **Step 2: Update node concepts and command docs**

Add the role matrix row:

```markdown
| `agent` | none | `gateway`, `app-development`, `app-production`, `database` |
```

Add role settings:

```markdown
| `agent` | `tld` with default `agent` during interactive `node:new` setup |
```

Add the rule:

```markdown
`agent` is creation-only. `node:new --role=agent` may create it; `node role:add <node> agent` must reject it.
```

- [ ] **Step 3: Document scoped grants**

Document:

```markdown
node_access is an edge from a consuming node to a serving node. The edge must exist before a caller can access the serving node. The edge stores normalized permission strings that decide what the caller may do.
```

Include examples:

```json
["node:read", "tool:read", "tool:restart", "tool:update:agent-tools", "doctor:verify"]
```

```json
["*"]
```

State that `*` is dynamic future authority and that `gateway-admin` is an explicit preset for `consumer -> gateway` grants.

- [ ] **Step 4: Document OpenClaw and Hermes as tools**

Create catalog docs for `openclaw` and `hermes` with:

```markdown
Support model: Installable and removable by Orbit
Category: `agent`
Required role: `agent`
Backend: Supervisor-managed runtime as `orbit-agent`
Service endpoint: internal HTTPS route under the agent role TLD
Credentials: web UI token/password metadata via `tool:credentials` when supported
Doctor: version, lifecycle, config, credentials metadata, route health
```

- [ ] **Step 5: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: PASS. If it fails, fix documentation structure issues before moving to code.

- [ ] **Step 6: Commit docs**

```bash
git add docs
git commit -m "docs: define agent node role and scoped grants"
```

### Task 2: Add Permission Storage

**Files:**
- Create: `database/migrations/2026_05_19_000000_add_permissions_to_node_access_table.php`
- Modify: `app/Models/NodeAccess.php`
- Test: `tests/Feature/Commands/Nodes/NodeGrantCommandTest.php`

- [ ] **Step 1: Write failing persistence test**

Add a test that creates a grant with permissions and asserts the stored JSON:

```php
it('stores normalized permissions on node access grants', function (): void {
    $consumer = Node::factory()->create(['name' => 'agent-1', 'status' => 'active']);
    $serving = Node::factory()->create(['name' => 'app-1', 'status' => 'active']);

    $grant = NodeAccess::query()->create([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $serving->id,
        'permissions' => ['tool:read', 'doctor:verify'],
    ]);

    expect($grant->fresh()->permissions)->toBe(['tool:read', 'doctor:verify']);
});
```

- [ ] **Step 2: Run test and verify failure**

```bash
php artisan test --compact --filter="stores normalized permissions"
```

Expected: FAIL because `permissions` does not exist or is not fillable/cast.

- [ ] **Step 3: Add migration**

Create the migration:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_access', function (Blueprint $table): void {
            $table->json('permissions')->nullable()->after('serving_node_id');
        });

        DB::table('node_access')->update(['permissions' => json_encode(['*'], JSON_THROW_ON_ERROR)]);
    }

    public function down(): void
    {
        Schema::table('node_access', function (Blueprint $table): void {
            $table->dropColumn('permissions');
        });
    }
};
```

- [ ] **Step 4: Update model**

Update `NodeAccess`:

```php
protected $fillable = [
    'consumer_node_id',
    'serving_node_id',
    'permissions',
];

protected function casts(): array
{
    return [
        'permissions' => 'array',
    ];
}
```

- [ ] **Step 5: Run test**

```bash
php artisan test --compact --filter="stores normalized permissions"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/NodeAccess.php tests/Feature/Commands/Nodes/NodeGrantCommandTest.php
git commit -m "feat: store node access permissions"
```

### Task 3: Build Permission Registry And Presets

**Files:**
- Create: `app/Services/Nodes/Access/NodePermissionRegistry.php`
- Create: `app/Services/Nodes/Access/NodePermissionNormalizer.php`
- Create: `app/Services/Nodes/Access/NodePermissionPresets.php`
- Test: `tests/Unit/Services/Nodes/Access/NodePermissionRegistryTest.php`
- Test: `tests/Unit/Services/Nodes/Access/NodePermissionNormalizerTest.php`
- Test: `tests/Unit/Services/Nodes/Access/NodePermissionPresetsTest.php`

- [ ] **Step 1: Write registry tests**

Cover:

```php
expect($registry->allows(['tool:read'], 'tool:logs'))->toBeTrue();
expect($registry->allows(['tool:read'], 'tool:credentials'))->toBeFalse();
expect($registry->allows(['tool:update'], 'tool:update:agent-tools'))->toBeTrue();
expect($registry->allows(['tool:update:agent-tools'], 'tool:update'))->toBeFalse();
expect($registry->allows(['node:*'], 'node:update'))->toBeTrue();
expect($registry->allows(['*'], 'firewall_rule:write'))->toBeTrue();
```

- [ ] **Step 2: Write normalizer tests**

Cover:

```php
expect($normalizer->normalize(['tool:read', 'tool:logs']))->toBe(['tool:read']);
expect($normalizer->normalize(['tool:logs', 'tool:read']))->toBe(['tool:read']);
expect(fn () => $normalizer->normalize(['tool:nope']))->toThrow(InvalidArgumentException::class);
```

- [ ] **Step 3: Write preset tests**

Cover:

```php
expect($presets->expand('agent-self'))->toBe([
    'node:read',
    'tool:read',
    'tool:restart',
    'tool:update:agent-tools',
    'doctor:verify',
]);

expect($presets->expand('operator'))->toContain('firewall_rule:read');
expect($presets->expand('operator'))->not->toContain('firewall_rule:write');
expect($presets->expand('gateway-admin'))->toBe(['*']);
```

- [ ] **Step 4: Implement registry**

Create registry with at least:

```php
private const array IMPLIED = [
    'tool:read' => ['tool:list', 'tool:show', 'tool:logs'],
    'node:read' => ['node:show', 'node:list'],
    'firewall_rule:read' => ['firewall_rule:list', 'firewall_rule:doctor'],
];
```

Implement wildcard matching:

```php
if (in_array('*', $permissions, true)) {
    return true;
}

[$namespace] = explode(':', $required, 2);

if (in_array("{$namespace}:*", $permissions, true)) {
    return true;
}
```

- [ ] **Step 5: Implement presets**

Add presets:

```php
'agent-self' => [
    'node:read',
    'tool:read',
    'tool:restart',
    'tool:update:agent-tools',
    'doctor:verify',
],
'operator' => [
    'node:read',
    'app:read',
    'app:write',
    'workspace:read',
    'workspace:write',
    'process:read',
    'process:start',
    'process:stop',
    'process:restart',
    'schedule:read',
    'schedule:write',
    'proxy:read',
    'tool:read',
    'tool:restart',
    'tool:update',
    'deploy:read',
    'deploy:run',
    'firewall_rule:read',
    'doctor:verify',
],
'gateway-admin' => ['*'],
```

- [ ] **Step 6: Run unit tests**

```bash
php artisan test --compact tests/Unit/Services/Nodes/Access
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Nodes/Access tests/Unit/Services/Nodes/Access
git commit -m "feat: add node access permission registry"
```

### Task 4: Enforce Permission-Aware Authorization

**Files:**
- Create: `app/Services/Nodes/Access/NodeAccessAuthorizer.php`
- Modify: API controllers that currently query `node_access` directly
- Test: `tests/Unit/Services/Nodes/Access/NodeAccessAuthorizerTest.php`
- Test: existing controller tests for tools, firewall, workspace, node grants

- [ ] **Step 1: Write authorizer tests**

Cover:

```php
expect($authorizer->allows($agent, $agent, 'tool:restart'))->toBeTrue();
expect($authorizer->allows($agent, $agent, 'tool:credentials'))->toBeFalse();
expect($authorizer->allows($agent, $agent, 'firewall_rule:write'))->toBeFalse();
expect($authorizer->allows($control, $gateway, 'node:new'))->toBeTrue(); // with gateway-admin
expect($authorizer->allows($control, $unrelatedNode, 'tool:read'))->toBeTrue(); // gateway-admin bypass
```

- [ ] **Step 2: Implement authorizer**

Rules:

```php
// Gateway host itself remains trusted for local gateway execution.
// A consumer -> gateway grant with '*' gives fleet-wide access.
// Otherwise, a consumer -> serving grant must exist and allow the required permission.
```

- [ ] **Step 3: Replace direct grant checks**

Search:

```bash
rg -n "node_access|consumer_node_id|serving_node_id" app/Http app/Services app/Actions
```

For each controller, replace ad hoc checks with `NodeAccessAuthorizer::allows()`.

- [ ] **Step 4: Preserve current passing behavior via backfilled `*`**

Existing tests that create binary grants should keep passing after helpers set `permissions => ['*']`.

- [ ] **Step 5: Run focused tests**

```bash
php artisan test --compact tests/Unit/Services/Nodes/Access tests/Feature/Http/Api/ToolTargetAuthorizationControllerTest.php tests/Unit/Services/Firewall/FirewallRuleIntentTest.php tests/Unit/Services/Firewall/FirewallRuleQueryTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app tests
git commit -m "feat: enforce scoped node access permissions"
```

### Task 5: Add Agent Role Definition And Baseline

**Files:**
- Modify: `app/Enums/Nodes/NodeRoleName.php`
- Create: `app/Data/Nodes/RoleSettings/AgentRoleSettings.php`
- Modify: `app/Services/Nodes/Roles/NodeRoleDefinition.php`
- Modify: `app/Services/Nodes/Roles/NodeRoleRegistry.php`
- Modify: `app/Services/Nodes/Roles/NodeRoleAssignments.php`
- Modify: `app/Services/Nodes/Roles/NodeRoleAssignmentService.php`
- Create: `app/Services/Nodes/Roles/RoleBaselines/AgentRoleBaseline.php`
- Modify: `app/Services/Nodes/Roles/NodeRoleBaselineConverger.php`
- Test: `tests/Feature/Services/Nodes/Roles/AgentRoleBaselineTest.php`
- Test: `tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php`

- [ ] **Step 1: Write role registry tests**

Assert:

```php
$definition = app(NodeRoleRegistry::class)->definition('agent');

expect($definition->conflictsWith)->toContain('gateway', 'app-development', 'app-production', 'database');
expect($definition->supportedPlatforms)->toBe(['ubuntu']);
expect($definition->settingsFromArray(['tld' => 'agent'])->toArray())->toBe(['tld' => 'agent']);
```

- [ ] **Step 2: Write role:add rejection test**

Assert `node role:add host-1 agent --json` fails with `validation_failed` and message explaining agent is only available through `node:new`.

- [ ] **Step 3: Implement enum and settings**

Add `NodeRoleName::Agent` and create `AgentRoleSettings` equivalent to `AppDevelopmentRoleSettings`, with message:

```php
'The agent role requires a valid tld setting.'
```

- [ ] **Step 4: Split role assignability**

Extend `NodeRoleDefinition` with:

```php
public bool $assignableByNodeNew = true,
public bool $assignableByRoleCommand = true,
```

Set `agent` to `assignableByNodeNew: true`, `assignableByRoleCommand: false`.

- [ ] **Step 5: Implement baseline**

`AgentRoleBaseline::converge()` must:

```php
$this->convergeTools($node, ['caddy', 'supervisor']);
$this->ensureOrbitAgentUser($node);
$this->developmentDnsMappingEnactor->convergeDevelopmentRole($node, $tld);
```

The user creation script must create an unprivileged user:

```bash
id -u orbit-agent >/dev/null 2>&1 || sudo useradd --create-home --shell /bin/bash orbit-agent
sudo passwd -l orbit-agent >/dev/null 2>&1 || true
```

- [ ] **Step 6: Generalize TLD uniqueness**

Update uniqueness checks to apply to `app-development` and `agent` role assignments. The same TLD cannot be active on two active nodes.

- [ ] **Step 7: Run focused tests**

```bash
php artisan test --compact tests/Feature/Services/Nodes/Roles/AgentRoleBaselineTest.php tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app tests
git commit -m "feat: add isolated agent node role"
```

### Task 6: Implement Grant And Permission UX For Node Commands

**Files:**
- Modify: `app/Console/Commands/NodeNewCommand.php`
- Modify: `app/Console/Commands/NodeGrantCommand.php`
- Create: `app/Console/Commands/NodePermissionsCommand.php`
- Modify: `app/Console/Commands/NodeRevokeCommand.php`
- Modify: `app/Http/Controllers/Api/NodeStoreController.php`
- Modify: `app/Http/Controllers/Api/NodeGrantController.php`
- Create: `app/Http/Controllers/Api/NodePermissionsController.php`
- Modify: `app/Http/Controllers/Api/NodeRevokeController.php`
- Modify: gateway request/response DTOs under `app/Http/Gateway/Requests/Nodes` and `app/Http/Gateway/Responses/Nodes`
- Test: node command feature tests

- [ ] **Step 1: Write node:grant tests**

Cover:

```php
node:grant agent-1 agent-1 --preset=agent-self --json
node:grant control-1 gateway-1 --preset=gateway-admin --force --json
node:grant agent-1 app-1 --preset=operator --json
node:grant agent-1 app-1 --permissions=tool:read,tool:logs --json
```

Expected:

- self-grant succeeds.
- gateway-admin requires confirmation or `--force`.
- redundant `tool:logs` is normalized away when `tool:read` is present.
- redundant permission inputs are reported as warnings in human output and under `success.meta.warnings[]` in JSON output.
- an existing grant is reported as already granted; permission updates after creation are owned by `node:permissions`.

- [ ] **Step 2: Write node:permissions tests**

Cover:

```php
node:permissions agent-1 app-1 --json
node:permissions agent-1 app-1 --preset=operator --json
node:permissions agent-1 app-1 --permissions=tool:read,doctor:verify --json
node:permissions agent-1 app-1 --add=tool:restart --json
node:permissions agent-1 app-1 --remove=tool:restart --json
node:permissions agent-1 app-2 --preset=operator --json
node:permissions agent-1 app-3 --add=tool:restart --json
```

Expected:

- read mode returns the current normalized permissions.
- `--preset` replaces the permission set with the normalized preset.
- `--permissions` accepts a comma-separated list and replaces the permission set with the normalized custom set.
- `--add` accepts comma-separated permissions and merges them into the current set before normalization. If no grant exists, it starts from an empty set and creates the grant when the normalized result is non-empty.
- `--remove` accepts comma-separated permissions and removes them from the current set before normalization. If no grant exists, it fails with `node.grant_not_found`.
- interactive selection can target any valid consuming and serving node pair the gateway-admin may administer. Existing grants preselect current permissions; missing grants preselect none and create the grant only after a valid non-empty permission set is submitted.
- read-only non-interactive requests for missing grant edges fail with `node.grant_not_found`.
- mutation responses report `success.data.action` as `created` for a new grant or `updated` for an existing grant.
- redundant permission inputs are reported as warnings in human output and under `success.meta.warnings[]` in JSON output.
- only callers with `consumer -> gateway` and `*` may run the command.
- `--preset`, `--permissions`, `--add`, and `--remove` are mutually exclusive modes, except one mode may receive a comma-separated list.

- [ ] **Step 3: Write node:new tests**

Cover:

```php
node:new agent-1 --role=agent --host=192.0.2.10 --tld=agent --self-grant=default --json
node:new agent-1 --role=agent --host=192.0.2.10 --agent-tool=openclaw --json
node:new agent-1 --role=agent --host=192.0.2.10 --agent-tool=openclaw --agent-tool=hermes --json
node:new agent-1 --role=agent --host=192.0.2.10 --grant-to=all --grant-to-preset=operator --json
node:new agent-1 --role=agent --host=192.0.2.10 --grant-from=control-1 --grant-from-permissions=node:read,tool:read --json
```

Expected:

- role assignment has `settings->tld = agent`.
- self grant exists with `agent-self` permissions.
- `--agent-tool=*` is repeatable; no agent tool is installed when omitted.
- multiple selected agent tools return the same structured warning used by `tool:install`.
- `--grant-to=all` expands to all current eligible serving nodes only.
- `--grant-from=all` expands to all current eligible consuming nodes only.
- gateway-admin is not offered by default.

- [ ] **Step 4: Implement node:grant permission inputs**

Add options:

```bash
--preset=<preset>
--permissions=<permission,permission>
--force
```

Rules:

- one of preset or permissions is required in non-interactive mode unless a documented default applies.
- custom permissions normalize before storage.
- `gateway-admin` or `*` to gateway requires `--force` in non-interactive mode and a confirm prompt in interactive mode.
- if the grant already exists, `node:grant` does not edit permissions; it returns the existing grant and points humans to `node:permissions`.

- [ ] **Step 5: Implement node:permissions command**

Interactive mode with missing arguments:

```text
Select consuming node
Select serving node
Select permissions
```

The permission prompt is a multiselect preselected with the current grant permissions. Submitting the multiselect replaces the permission set with exactly the selected normalized permissions.
If no grant exists for the selected consuming and serving node pair, the permission prompt starts empty and submitting a valid non-empty permission set creates the grant edge.
Human output states whether the command created a new grant or updated an existing grant.

Non-interactive mode:

```bash
orbit node:permissions agent-1 app-1 --json
orbit node:permissions agent-1 app-1 --preset=operator --json
orbit node:permissions agent-1 app-1 --permissions=tool:read,doctor:verify --json
orbit node:permissions agent-1 app-1 --add=tool:restart --json
orbit node:permissions agent-1 app-1 --remove=tool:restart --json
```

The command must fail with `authorization_failed` unless the caller has gateway-admin authority.
Read-only requests and `--remove` requests must fail with `node.grant_not_found` when the consuming node has no existing grant to the serving node.
Replacement requests through `--preset` or `--permissions` must create the missing grant after authorization, node-pair, and permission validation succeed.
`--add` requests may create a missing grant by treating the current permission set as empty and then applying normalization.
JSON mutation responses include `success.data.action` with `created` or `updated`.

- [ ] **Step 6: Implement node:new grant prompts**

Prompt order:

```text
self grant: default/custom
Which agent tools should Orbit install? [none]
Does this node need access to other nodes? [no]
Should other nodes need access to this node? [no]
```

For `custom`, start from role-union defaults and allow adjustment.
The agent tool prompt is a `multiselect` with `openclaw` and `hermes`. Selecting more than one asks the same multiple-agent-tool confirmation used by `tool:install`.
Use directional non-interactive grant flags:

```bash
--grant-to=<node|all>
--grant-to-preset=<preset>
--grant-to-permissions=<permission,permission>
--grant-from=<node|all>
--grant-from-preset=<preset>
--grant-from-permissions=<permission,permission>
--agent-tool=<tool>
```

- [ ] **Step 7: Run focused tests**

```bash
php artisan test --compact tests/Feature/Commands/Nodes/NodeGrantCommandTest.php tests/Feature/Commands/Nodes/NodePermissionsCommandTest.php tests/Feature/Commands/Nodes/NodeRevokeCommandTest.php tests/Feature/Commands/Nodes/NodeNewCommandTest.php tests/Feature/Commands/Nodes/AgentNodeNewCommandTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app tests
git commit -m "feat: add permission-aware node grant UX"
```

### Task 7: Add OpenClaw And Hermes Tool Definitions

**Files:**
- Create: `app/Tools/OpenClawTool.php`
- Create: `app/Tools/HermesTool.php`
- Modify: `app/Contracts/ToolDefinition.php`
- Modify: `app/Services/Tools/ToolCatalog.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Commands/Tools/AgentToolInstallCommandTest.php`

- [ ] **Step 1: Recheck upstream install/update docs**

Before writing scripts, re-open current official docs for:

- OpenClaw install/update/doctor/systemd/gateway commands.
- Hermes install/update/doctor/gateway commands.

Use current docs because these tools are moving quickly.

- [ ] **Step 2: Write definition tests**

Assert:

```php
expect($catalog->supports('openclaw'))->toBeTrue();
expect($catalog->requiredNodeRole('openclaw'))->toBe('agent');
expect($catalog->supports('hermes'))->toBeTrue();
expect($catalog->requiredNodeRole('hermes'))->toBe('agent');
expect($catalog->category('openclaw'))->toBe('agent');
```

- [ ] **Step 3: Add category metadata**

Prefer adding to `ToolDefinition`:

```php
public function category(): string;
```

Backfill existing tools with their documented categories.

- [ ] **Step 4: Implement OpenClaw tool**

Minimum Linux install path:

```bash
sudo -u orbit-agent -H bash -lc 'curl -fsSL https://openclaw.ai/install.sh | bash -s -- --no-onboard'
```

Minimum verify commands:

```bash
sudo -u orbit-agent -H bash -lc 'openclaw --version'
sudo -u orbit-agent -H bash -lc 'openclaw doctor'
sudo -u orbit-agent -H bash -lc 'openclaw gateway status'
```

Update path:

```bash
sudo -u orbit-agent -H bash -lc 'npm install -g openclaw@latest'
```

If current upstream docs provide a safer native update command, use that instead and record the source in `openclaw.md`.

- [ ] **Step 5: Implement Hermes tool**

Minimum Linux install path:

```bash
sudo -u orbit-agent -H bash -lc 'curl -fsSL https://raw.githubusercontent.com/NousResearch/hermes-agent/main/scripts/install.sh | bash'
```

Verify:

```bash
sudo -u orbit-agent -H bash -lc 'hermes doctor'
```

Update:

```bash
sudo -u orbit-agent -H bash -lc 'hermes update'
```

- [ ] **Step 6: Register tools**

Add both definitions to `ToolDefinitionRegistry` setup in `AppServiceProvider`.

- [ ] **Step 7: Run focused tests**

```bash
php artisan test --compact tests/Feature/Commands/Tools/AgentToolInstallCommandTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app tests docs/domains/3_tool/catalog
git commit -m "feat: add OpenClaw and Hermes tool definitions"
```

### Task 8: Agent Tool Authorization And Warnings

**Files:**
- Modify: `app/Services/Tools/ToolInstaller.php`
- Modify: `app/Services/Tools/ToolUpdater.php`
- Modify: `app/Services/Tools/ToolLifecycleManager.php`
- Modify: `app/Services/Tools/ToolCredentialsReader.php`
- Modify: `app/Console/Commands/ToolInstallCommand.php`
- Modify: `app/Console/Commands/ToolStartCommand.php`
- Modify: `app/Console/Commands/ToolUpdateCommand.php`
- Modify: `app/Console/Commands/ToolCredentialsCommand.php`
- Test: `tests/Feature/Commands/Tools/AgentToolAuthorizationTest.php`

- [ ] **Step 1: Write authorization tests**

Cover agent self:

```php
tool:update openclaw --node=agent-1 --json       // allowed
tool:restart openclaw --node=agent-1 --json      // allowed
tool:credentials openclaw --node=agent-1 --json  // denied
tool:install hermes --node=agent-1 --json        // denied when caller is agent self
tool:remove openclaw --node=agent-1 --json       // denied
tool:stop openclaw --node=agent-1 --json         // denied
tool:reconfigure openclaw --node=agent-1 --json  // denied
tool:update caddy --node=agent-1 --json          // denied
```

- [ ] **Step 2: Write multiple running agent tool warning tests**

Interactive human mode:

```text
Orbit discourages running multiple agent tools on one agent node because activity is attributed at node level. Continue?
```

JSON mode:

```json
{
  "success": {
    "meta": {
      "warnings": [
        {
          "code": "tool.multiple_agent_tools_running",
          "tools": ["openclaw", "hermes"]
        }
      ]
    }
  }
}
```

- [ ] **Step 3: Enforce self update restriction**

When caller and target node are the same active `agent` node:

- require `tool:update:agent-tools` for update;
- require tool category `agent`;
- require `tool:restart` for restart;
- reject credentials unless a separate `tool:credentials` permission exists.

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Commands/Tools/AgentToolAuthorizationTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "feat: enforce agent tool permissions"
```

### Task 9: Tool-Owned Internal Routes And Credentials

**Files:**
- Modify tool route/proxy services used by existing tool-owned endpoints
- Modify: `app/Services/Tools/ToolInstaller.php`
- Modify: `app/Services/Tools/ToolCredentialsReader.php`
- Modify: `app/Services/Tools/ToolsProbe.php`
- Test: `tests/Feature/Commands/Tools/AgentToolInstallCommandTest.php`
- Test: `tests/Feature/Commands/Tools/ToolCredentialsCommandTest.php`

- [ ] **Step 1: Write proxy route tests**

Installing `openclaw` on an agent node with `tld=agent` should create a tool-owned route:

```php
expect($route->hostname)->toBe('openclaw.agent');
expect($route->owner_type)->toBe('tool');
expect($route->owner_name)->toBe('openclaw');
```

Installing `hermes` should create `hermes.agent`.

- [ ] **Step 2: Write credentials tests**

Operator with `tool:credentials` may read web UI token:

```php
expect($payload['success']['data']['credentials'])->toMatchArray([
    'tool' => 'openclaw',
    'node' => 'agent-1',
]);
```

Agent self without `tool:credentials` receives `authorization_failed`.

- [ ] **Step 3: Implement route creation**

Resolve agent TLD from active `agent` role settings. If absent, fail with:

```text
The selected node does not have an active agent role TLD.
```

- [ ] **Step 4: Store credentials metadata**

Do not put secrets in activity properties. Store encrypted credentials on `node_tools.credentials` using the existing encrypted cast.

- [ ] **Step 5: Run focused tests**

```bash
php artisan test --compact tests/Feature/Commands/Tools/AgentToolInstallCommandTest.php tests/Feature/Commands/Tools/ToolCredentialsCommandTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app tests
git commit -m "feat: expose agent tool internal routes"
```

### Task 10: Doctor And Verification

**Files:**
- Modify: node doctor services
- Modify: tool doctor services
- Modify: docs doctor contracts if implementation finds a gap
- Test: `tests/Feature/Commands/Operations/DoctorCommandContractTest.php`
- Test: `tests/Unit/Services/Tools/ToolsProbeTest.php`
- Test: node probe tests

- [ ] **Step 1: Add node doctor tests**

Node doctor reports:

- missing agent TLD setting;
- missing agent DNS mapping;
- missing Caddy baseline tool;
- missing Supervisor baseline tool;
- missing `orbit-agent` user;
- invalid scoped grant permission.

- [ ] **Step 2: Add tool doctor tests**

Tool doctor reports:

- OpenClaw/Hermes binary missing;
- version mismatch when version tracking is enabled;
- service stopped when expected running;
- missing credentials metadata when the tool declares credentials;
- missing internal proxy route when the tool declares a web UI.

- [ ] **Step 3: Implement probes/fixes**

Use existing node and tool probe patterns. Do not add a new doctor family.

- [ ] **Step 4: Run focused doctor tests**

```bash
php artisan test --compact tests/Feature/Commands/Operations/DoctorCommandContractTest.php tests/Unit/Services/Tools/ToolsProbeTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app tests docs
git commit -m "feat: verify agent nodes and tools in doctor"
```

### Task 11: Focused E2E Smoke

**Files:**
- Create: `tests/E2E/Ephemeral/AgentNodeProvisioningTest.php`
- Modify: E2E topology helpers if they need an `agent` role fixture

- [ ] **Step 1: Add ephemeral test**

The test should:

1. Bootstrap gateway/control topology.
2. Run `node:new agent-1 --role=agent --host=<host> --tld=agent --self-grant=default --json`.
3. Assert the node has an active `agent` role.
4. Assert `node_access` contains a self-grant with the `agent-self` permission set.
5. Install one fake/stubbed agent tool if live OpenClaw/Hermes install is too slow for the normal E2E lane.
6. Run `doctor --family=node --node=agent-1 --json`.

- [ ] **Step 2: Run E2E smoke**

```bash
php artisan test --compact tests/E2E/Ephemeral/AgentNodeProvisioningTest.php
```

Expected: PASS in the ephemeral lane.

- [ ] **Step 3: Commit**

```bash
git add tests/E2E app/E2E
git commit -m "test: cover agent node provisioning e2e"
```

### Task 12: Final Quality Gate

**Files:** all touched files

- [ ] **Step 1: Run focused tests**

```bash
php artisan test --compact tests/Unit/Services/Nodes/Access tests/Feature/Commands/Nodes tests/Feature/Commands/Tools tests/Feature/Commands/Operations/DoctorCommandContractTest.php
```

Expected: PASS.

- [ ] **Step 2: Run formatting**

```bash
vendor/bin/pint --dirty --format agent
```

Expected: no remaining dirty formatting changes after Pint runs.

- [ ] **Step 3: Run docs lint**

```bash
composer docs-lint
```

Expected: PASS.

- [ ] **Step 4: Run broader quality check**

```bash
composer quality-check
```

Expected: PASS.

- [ ] **Step 5: Commit final fixes**

```bash
git status --short
git add .
git commit -m "test: verify agent node role"
```

Skip the final commit only if `git status --short` is clean.

---

## Plan Self-Review

Spec coverage:

- Agent role as creation-only hosted role: Tasks 1, 5, 6.
- Role exclusivity and TLD settings: Tasks 1, 5.
- Caddy/Supervisor/WireGuard-ish baseline and `orbit-agent` user: Task 5.
- Scoped permissions and self-grants: Tasks 1, 2, 3, 4, 6.
- `node:permissions` as a gateway-admin upsert path: Task 6.
- Directional `node:new` grant setup for access-to and access-from relationships: Task 6.
- Gateway-admin semantics: Tasks 1, 3, 4, 6.
- Operator preset excluding firewall writes: Tasks 1, 3, 4.
- Agent self permissions excluding credentials and node update: Tasks 1, 3, 4, 8.
- No default agent tool and optional `node:new` agent tool selection: Tasks 1, 6, 7, 8.
- OpenClaw/Hermes as tools: Tasks 1, 7, 8, 9, 10.
- Multiple running agent tools warning: Task 8.
- Node-level activity only: Task 1; existing activity model remains.
- Doctor and verification: Tasks 10, 11, 12.

Open implementation risks:

- OpenClaw and Hermes upstream installer/update commands may change. Task 7 explicitly rechecks current official docs before script implementation.
- `tool:update:agent-tools` is category-aware and needs enforcement at the tool action layer, not only in the generic permission registry.
- Tool-owned internal proxy route implementation may need a small proxy-family contract update once the current route owner model is inspected in implementation.
- Refactoring every direct `node_access` query into `NodeAccessAuthorizer` is broad. Keep this as a dedicated task and do not mix it with role/tool work.
