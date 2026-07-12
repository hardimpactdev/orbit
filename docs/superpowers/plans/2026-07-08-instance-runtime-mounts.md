# Instance Runtime Mounts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move configurable FrankenPHP runtime mounts from the logical app layer to the app-instance layer so `hauser:development` and `hauser:nmbp` can carry different host paths.

**Architecture:** Keep `App -> AppInstance -> Workspace` as the current product model. Add instance-scoped runtime mount storage and command/API behavior, keep legacy app-level mounts as a read-only compatibility fallback for existing records, and make app/workspace container renderers prefer selected-instance mounts when an `AppInstance` exists.

**Tech Stack:** Laravel 13 gateway, Laravel Zero CLI, Pest, SQLite migrations, Orbit FrankenPHP runtime renderers.

## Global Constraints

- Do not introduce a Project model in this slice.
- Keep the logical app record names `hauser` and `happie`; `nmbp` and `development` are app instances.
- Runtime mounts are concrete runtime/container concerns and belong to app instances.
- Existing app-level mounts must not break immediately; use them as fallback only when no selected instance mounts exist.
- Workspace runtime containers must use the selected workspace app instance's mounts.
- Command examples must support dotted selectors such as `hauser.nmbp`.
- Verify with focused gateway/CLI tests before live Hauser/Happie smoke.

---

## File Structure

- Create `apps/gateway/database/migrations/2026_07_08_000000_create_app_instance_runtime_mounts_table.php`
  - Stores source/target/read-only rows keyed by `app_instance_id`.
- Create `apps/gateway/app/Models/AppInstanceRuntimeMount.php`
  - Eloquent model for instance-scoped mounts.
- Modify `apps/gateway/app/Models/AppInstance.php`
  - Add `runtimeMounts()` relation and property docblock.
- Modify `apps/gateway/app/Services/Apps/AppRuntimeMountService.php`
  - Resolve target as `App` or `AppInstance`, validate against the target node, list/add/remove instance mounts, and expose renderer helpers.
- Modify `apps/gateway/app/Http/Controllers/Api/AppRuntimeMountController.php`
  - Resolve dotted `app.instance` selectors, return target metadata, and route writes to instance mounts when an instance is selected.
- Modify `apps/cli/app/Commands/App/AppMountCommand.php`
  - Keep command name for now, but update description and output to instance-aware runtime mounts.
- Modify app/workspace runtime renderers:
  - `apps/gateway/app/Services/Apps/AppRuntimeContainerRenderer.php`
  - `apps/gateway/app/Services/Workspaces/WorkspaceRuntimeContainerRenderer.php`
  - Prefer instance mounts when an app instance is present; fall back to legacy app mounts otherwise.
- Modify tests:
  - `apps/gateway/tests/Feature/Http/Api/AppRuntimeMountControllerTest.php`
  - `apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php`
  - `apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerRendererTest.php`
  - `apps/gateway/tests/Unit/Services/Workspaces/WorkspaceRuntimeContainerRendererTest.php`

---

### Task 1: Add Instance Runtime Mount Storage

**Files:**
- Create: `apps/gateway/database/migrations/2026_07_08_000000_create_app_instance_runtime_mounts_table.php`
- Create: `apps/gateway/app/Models/AppInstanceRuntimeMount.php`
- Modify: `apps/gateway/app/Models/AppInstance.php`
- Test: `apps/gateway/tests/Feature/Http/Api/AppRuntimeMountControllerTest.php`

**Interfaces:**
- Produces: `AppInstance::runtimeMounts(): HasMany`
- Produces: `AppInstanceRuntimeMount` with `app_instance_id`, `source`, `target`, `read_only`

- [ ] **Step 1: Write the failing model/storage test**

Add a test in `AppRuntimeMountControllerTest.php`:

```php
it('stores runtime mounts on an app instance independently from legacy app mounts', function (): void {
    $caller = createAppRuntimeMountCaller();
    $appNode = createTestAppHostNode(['name' => 'NMBP', 'platform' => 'macos_14', 'user' => 'nckrtl']);
    grantAppRuntimeMountAccess($caller, $appNode, ['app:read', 'app:mount']);

    $app = App::factory()->for($appNode, 'node')->create([
        'name' => 'hauser',
        'path' => '/Users/nckrtl/apps/hauser',
        'document_root' => 'public',
    ]);
    $instance = $app->instances()->create([
        'name' => 'nmbp',
        'driver' => AppInstanceDriver::Orbit,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $appNode->id,
            node: 'NMBP',
            path: '/Users/nckrtl/apps/hauser',
            document_root: 'public',
            domain: 'hauser.nmbp',
        ),
    ]);

    postAppRuntimeMountJson('/api/apps/hauser.nmbp/mounts', [
        'source' => '/Users/nckrtl/projects',
        'target' => '/projects',
        'read_only' => true,
    ])->assertOk();

    expect($app->runtimeMounts()->count())->toBe(0)
        ->and($instance->runtimeMounts()->count())->toBe(1)
        ->and($instance->runtimeMounts()->first()?->source)->toBe('/Users/nckrtl/projects');
});
```

Run:

```bash
bin/orbit-gateway-pest apps/gateway/tests/Feature/Http/Api/AppRuntimeMountControllerTest.php --filter='stores runtime mounts on an app instance'
```

Expected: fail because `app_instance_runtime_mounts` and `AppInstance::runtimeMounts()` do not exist.

- [ ] **Step 2: Add migration and model**

Create the migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_instance_runtime_mounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_instance_id')->constrained()->cascadeOnDelete();
            $table->string('source', 512);
            $table->string('target', 512);
            $table->boolean('read_only')->default(true);
            $table->timestamps();

            $table->unique(['app_instance_id', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_instance_runtime_mounts');
    }
};
```

Create `AppInstanceRuntimeMount` mirroring `AppRuntimeMount`, with `belongsTo(AppInstance::class)`.

- [ ] **Step 3: Add the relation**

In `AppInstance`, add:

```php
/**
 * @return HasMany<AppInstanceRuntimeMount, $this>
 */
public function runtimeMounts(): HasMany
{
    return $this->hasMany(AppInstanceRuntimeMount::class)->orderBy('target');
}
```

- [ ] **Step 4: Verify storage test passes**

Run the focused test again. Expected: pass after controller/service task is implemented; if it still fails at route/service resolution, leave it failing and proceed to Task 2.

---

### Task 2: Make Mount API and CLI Instance-Aware

**Files:**
- Modify: `apps/gateway/app/Services/Apps/AppRuntimeMountService.php`
- Modify: `apps/gateway/app/Http/Controllers/Api/AppRuntimeMountController.php`
- Modify: `apps/cli/app/Commands/App/AppMountCommand.php`
- Test: `apps/gateway/tests/Feature/Http/Api/AppRuntimeMountControllerTest.php`
- Test: `apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php`

**Interfaces:**
- Consumes: `AppInstance::runtimeMounts()`
- Produces: mount payloads for either `App` legacy target or `AppInstance` target.
- Produces: dotted selector support: `hauser.nmbp`.

- [ ] **Step 1: Write API behavior tests**

Add tests proving:

```php
postAppRuntimeMountJson('/api/apps/hauser.nmbp/mounts', [
    'source' => '/Users/nckrtl/projects',
    'target' => '/projects',
])->assertOk()
    ->assertJsonPath('success.data.target.type', 'app_instance')
    ->assertJsonPath('success.data.target.app', 'hauser')
    ->assertJsonPath('success.data.target.instance', 'nmbp')
    ->assertJsonPath('success.data.mount.source', '/Users/nckrtl/projects');
```

and:

```php
postAppRuntimeMountJson('/api/apps/hauser.nmbp/mounts', [
    'source' => '/home/nckrtl/projects',
    'target' => '/projects',
])->assertStatus(422)
    ->assertJsonPath('error.code', 'validation_failed')
    ->assertJsonPath('error.meta.home', '/Users/nckrtl');
```

Run:

```bash
bin/orbit-gateway-pest apps/gateway/tests/Feature/Http/Api/AppRuntimeMountControllerTest.php
```

Expected: fail until selector resolution and instance validation exist.

- [ ] **Step 2: Resolve dotted selectors**

In `AppRuntimeMountController`, replace `resolveApp()` with a resolver that returns:

```php
/**
 * @return array{app: App, instance: AppInstance|null}|null
 */
private function resolveMountTarget(string $selector): ?array
```

Behavior:
- Exact app name/domain resolves legacy app target.
- Selector containing one dot tries `app.instance` first.
- For `hauser.nmbp`, load `App::where('name', 'hauser')` and its `instances()->where('name', 'nmbp')`.

- [ ] **Step 3: Update validation to use target node**

In `AppRuntimeMountService`, add target-aware validation:

```php
public function addToInstance(AppInstance $instance, string $source, string $target, bool $readOnly = true): array
public function removeFromInstance(AppInstance $instance, string $target): array
public function listForInstance(AppInstance $instance): Collection
```

The node/home used for validation comes from the instance driver config for Orbit instances:
- macOS/NMBP user `nckrtl` -> `/Users/nckrtl`
- Linux/beast user `nckrtl` -> `/home/nckrtl`

Reuse existing node/user validation helpers, but parameterize them by resolved node and runtime kind instead of only `App`.

- [ ] **Step 4: Update CLI tests**

In `AppWriteCommandTest`, add a request assertion that:

```php
runCommand($this, 'app:mount', [
    'action' => 'add',
    'app' => 'hauser.nmbp',
    'source' => '/Users/nckrtl/projects',
    'target' => '/projects',
    '--json' => true,
]);
```

POSTs to:

```text
https://gateway.test/api/apps/hauser.nmbp/mounts
```

Expected: existing command path already supports this once gateway does.

- [ ] **Step 5: Update CLI description**

Change command description from:

```php
protected $description = 'List or change additional Docker runtime mounts for an app.';
```

to:

```php
protected $description = 'List or change additional Docker runtime mounts for an app instance.';
```

Keep command name `app:mount` for this slice.

---

### Task 3: Render Instance Mounts in App and Workspace Containers

**Files:**
- Modify: `apps/gateway/app/Services/Apps/AppRuntimeContainerRenderer.php`
- Modify: `apps/gateway/app/Services/Workspaces/WorkspaceRuntimeContainerRenderer.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerRendererTest.php`
- Test: `apps/gateway/tests/Unit/Services/Workspaces/WorkspaceRuntimeContainerRendererTest.php`

**Interfaces:**
- Consumes: `AppRuntimeMountService::mountsForRuntime(App $app, ?AppInstance $instance = null): array`
- Produces: renderer behavior that prefers instance mounts and falls back to legacy app mounts.

- [ ] **Step 1: Write renderer tests**

Replace the workspace test named `inherits configured app runtime mounts from the parent app` with one that proves selected instance mounts win:

```php
it('uses selected app instance runtime mounts for workspace containers', function (): void {
    $node = createTestAppHostNode(['name' => 'NMBP', 'platform' => 'macos_14', 'user' => 'nckrtl']);
    $app = makeWorkspaceRendererApp($node, [
        'name' => 'hauser',
        'path' => '/Users/nckrtl/apps/hauser',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $app->runtimeMounts()->create([
        'source' => '/home/nckrtl/projects',
        'target' => '/projects',
        'read_only' => true,
    ]);
    $instance = $app->instances()->create([
        'name' => 'nmbp',
        'driver' => AppInstanceDriver::Orbit,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: 'NMBP',
            path: '/Users/nckrtl/apps/hauser',
            document_root: 'public',
            domain: 'hauser.nmbp',
        ),
    ]);
    $instance->runtimeMounts()->create([
        'source' => '/Users/nckrtl/projects',
        'target' => '/projects',
        'read_only' => true,
    ]);
    $workspace = makeWorkspaceRendererWorkspace($app, [
        'name' => 'feature-a',
        'path' => '/Users/nckrtl/apps/hauser/.worktrees/feature-a',
        'app_instance_id' => $instance->id,
        'php_version' => null,
    ]);
    $workspace->setRelation('appInstance', $instance);

    $mounts = workspaceRendererForTest()->render($workspace)->mounts();

    expect($mounts)->toContain([
        'source' => '/Users/nckrtl/projects',
        'target' => '/projects',
        'read_only' => true,
    ])->and($mounts)->not->toContain([
        'source' => '/home/nckrtl/projects',
        'target' => '/projects',
        'read_only' => true,
    ]);
});
```

Run:

```bash
bin/orbit-gateway-pest apps/gateway/tests/Unit/Services/Workspaces/WorkspaceRuntimeContainerRendererTest.php --filter='selected app instance runtime mounts'
```

Expected: fail until renderer uses instance mounts.

- [ ] **Step 2: Update mount service renderer API**

Change renderer helper to:

```php
public function mountsForRuntime(App $app, ?AppInstance $instance = null): array
```

Behavior:
- If `$instance` has one or more runtime mounts, return those.
- Otherwise return legacy `$app->runtimeMounts` for compatibility.
- Built-in `/packages` mount remains unchanged.

- [ ] **Step 3: Pass app instance from workspace renderer**

In `WorkspaceRuntimeContainerRenderer`, load and pass the selected app instance:

```php
$workspace->loadMissing(['app.node.roleAssignments', 'app.runtimeMounts', 'appInstance.runtimeMounts']);
$configuredMounts = $this->mounts->mountsForRuntime($app, $workspace->appInstance);
```

- [ ] **Step 4: Add spec hash coverage**

Update the existing spec-hash test so changing `AppInstanceRuntimeMount` changes the workspace runtime spec hash. Keep the legacy app-level spec-hash test as fallback coverage.

---

### Task 4: Product Docs and Decision Ledger

**Files:**
- Modify: `PRODUCT_DECISIONS.md`
- Modify docs under `apps/docs/content/domains/**` that mention `app:mount` or app-level mounts.
- Test: docs lint.

**Interfaces:**
- Produces: product docs that state mounts are instance-scoped under `App -> AppInstance -> Workspace`.

- [ ] **Step 1: Update product decision ledger**

Add a newer dated entry near the 2026-06-17 app-instance decision:

```markdown
- 2026-07-08 — Configurable FrankenPHP runtime mounts belong to app instances, not logical apps: `app:mount` accepts instance selectors such as `hauser.nmbp`, app and workspace runtime containers use selected-instance mounts, and legacy app-level mounts remain compatibility fallback only until migrated.
```

- [ ] **Step 2: Update command/domain docs**

Search:

```bash
rg -n "app:mount|runtime mounts|app-level runtime mounts|configured app runtime mounts" apps/docs/content
```

Change docs to describe:
- `orbit app:mount add <app>.<instance> <source> <target>`
- `hauser.development` and `hauser.nmbp` can use different host paths.
- Legacy bare app mounts are compatibility fallback only.

- [ ] **Step 3: Run docs lint**

```bash
composer docs-lint
```

Expected: pass.

---

### Task 5: Migrate Live Hauser Mount Intent and Re-run Smoke

**Files:**
- No repository files unless a migration command/script is added during implementation.

**Interfaces:**
- Consumes: instance-aware `app:mount`.
- Produces: live Orbit registry state where `hauser:nmbp` uses `/Users/nckrtl/projects` and `hauser:development` uses `/home/nckrtl/projects`.

- [ ] **Step 1: Apply live mount intent**

After code is installed in the live Orbit CLI/gateway path, run:

```bash
orbit app:mount remove hauser /projects --json
orbit app:mount add hauser.development /home/nckrtl/projects /projects --json
orbit app:mount add hauser.nmbp /Users/nckrtl/projects /projects --json
```

Expected:
- Bare legacy mount is removed or left only if compatibility fallback is intentionally retained and harmless.
- `hauser.development` and `hauser.nmbp` list different source paths for `/projects`.

- [ ] **Step 2: Re-run fresh workspace smoke**

Use fresh workspace names:

```bash
ORBIT_WORKSPACE_NAME=codex-thread-hauser-<id> ORBIT_WORKTREE_PATH=<fresh-hauser-worktree> zsh <hauser environment setup>
ORBIT_WORKSPACE_NAME=codex-thread-happie-<id> ORBIT_WORKTREE_PATH=<fresh-happie-worktree> zsh <happie environment setup>
```

Then verify:

```bash
orbit workspace:show codex-thread-hauser-<id> --app=hauser.nmbp --json
orbit process:list --app=hauser.nmbp --workspace=codex-thread-hauser-<id> --json
orbit workspace:show codex-thread-happie-<id> --app=happie.nmbp --json
orbit process:list --app=happie.nmbp --workspace=codex-thread-happie-<id> --json
```

Expected:
- Hauser workspace runtime container installs.
- Hauser URL renders a non-empty page, no 502.
- Happie URL still renders `Home - Happie`.
- VitePlus process handling is recorded explicitly; if Hauser uses `dev` and Happie uses `vite`, the environment script follow-up is tracked separately unless this slice includes process startup selection.

---

## Verification

Run the focused lanes first:

```bash
bin/orbit-gateway-pest apps/gateway/tests/Feature/Http/Api/AppRuntimeMountControllerTest.php
bin/orbit-gateway-pest apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerRendererTest.php
bin/orbit-gateway-pest apps/gateway/tests/Unit/Services/Workspaces/WorkspaceRuntimeContainerRendererTest.php
bin/orbit-cli-pest apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php --filter='app:mount'
composer docs-lint
```

Before final handoff:

```bash
composer quality-check
```

Live proof after deployment/relink:

```bash
orbit app:mount list hauser.development --json
orbit app:mount list hauser.nmbp --json
orbit doctor --node=NMBP --family=workspace --workspace=<fresh-hauser-workspace> --json
```

Expected live result: no `/home/nckrtl/projects` mount appears in any `hauser:nmbp` runtime/workspace container.

## Self-Review

- Spec coverage: The plan keeps `App -> AppInstance -> Workspace`, adds instance-scoped mount storage/API/CLI/rendering, preserves legacy fallback, and includes live Hauser/Happie smoke.
- Placeholder scan: No `TBD`, `TODO`, or unnamed tests remain.
- Type consistency: `AppInstanceRuntimeMount`, `AppInstance::runtimeMounts()`, and `AppRuntimeMountService::mountsForRuntime(App $app, ?AppInstance $instance = null)` are consistently named across tasks.
