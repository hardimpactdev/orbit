# Composable Node Roles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Orbit's single `nodes.role` app/control split with composable hosted node roles, while keeping the gateway special and treating CLI operation as identity plus authorization grants.

**Architecture:** Store role assignments in a new `node_roles` table with per-role status and typed JSON settings. Define role behavior in code through a registry: compatibility, supported platforms, settings DTOs, eligibility checks, baseline convergence, dependency checks, and cleanup semantics. Keep grants separate from roles: a joined node can request self-scoped actions, but the gateway authorizes each action and explicit `node_access` rows are only for operating other nodes.

**Tech Stack:** Laravel 13, Eloquent models/migrations, Pest feature and unit tests, existing gateway API command pattern, existing node doctor family, Laravel Pint.

---

## Status

**Current docs contradict this plan.** `docs/commands/1_node/README.md`, `node-concepts.md`, `node-doctor.md`, and many command contracts still define exactly three mutually exclusive roles: `control`, `gateway`, and `app`. Implementation must update docs before changing behavior.

**Out of scope for this plan:**
- `database:*` resource commands.
- `file-storage` implementation and RustFS provisioning.
- Database migration/move/backup workflows.
- Configurable user-defined roles.
- Role-specific setting overrides outside the fixed code-defined role contract.

**Initial role set:**
- `gateway`: special singleton authority, stored as a role assignment, exclusive with every other role, not assignable through normal `node role:add`.
- `app-development`: hosted role, mutually exclusive with `app-production`, compatible with `database`.
- `app-production`: hosted role, mutually exclusive with `app-development`, compatible with `database`.
- `database`: hosted role, compatible with both app roles and valid standalone.

**Compatibility matrix:**

| Role | Combines With | Conflicts With |
| --- | --- | --- |
| `gateway` | none | all other roles |
| `app-development` | `database` | `gateway`, `app-production` |
| `app-production` | `database` | `gateway`, `app-development` |
| `database` | `app-development`, `app-production` | `gateway` |

**Role assignment statuses:**
- `pending`: desired role has been stored but convergence has not completed.
- `active`: role baseline is converged and can be used for eligibility checks.
- `error`: convergence failed; doctor can retry restore after blockers are addressed.
- `removing`: cleanup is in progress or failed; role is not eligible for new resources.

## File Map

### New Files

- `database/migrations/2026_05_17_000000_create_node_roles_table.php` — normalized role assignment table.
- `app/Models/NodeRoleAssignment.php` — Eloquent model for `node_roles`.
- `database/factories/NodeRoleAssignmentFactory.php` — factory states for role/status combinations.
- `app/Enums/Nodes/NodeRoleName.php` — role names: `gateway`, `app-development`, `app-production`, `database`.
- `app/Enums/Nodes/NodeRoleStatus.php` — assignment statuses.
- `app/Data/Nodes/RoleSettings/NodeRoleSettings.php` — settings DTO interface.
- `app/Data/Nodes/RoleSettings/EmptyRoleSettings.php` — settings for roles with no fields.
- `app/Data/Nodes/RoleSettings/AppDevelopmentRoleSettings.php` — `tld`.
- `app/Data/Nodes/RoleSettings/AppProductionRoleSettings.php` — empty for v1.
- `app/Data/Nodes/RoleSettings/DatabaseRoleSettings.php` — empty for v1.
- `app/Services/Nodes/Roles/NodeRoleDefinition.php` — immutable role definition.
- `app/Services/Nodes/Roles/NodeRoleRegistry.php` — code-defined role catalog.
- `app/Services/Nodes/Roles/NodeRoleAssignments.php` — query helper for active roles and eligibility.
- `app/Services/Nodes/Roles/NodeRoleAssignmentService.php` — add/update/remove/list role orchestration.
- `app/Services/Nodes/Roles/NodeRoleBaselineConverger.php` — sync baseline enactor facade.
- `app/Services/Nodes/Roles/NodeRoleDependencyInspector.php` — blocks removals and builds force/purge plans.
- `app/Services/Nodes/Roles/RoleBaselines/RoleBaseline.php` — baseline interface.
- `app/Services/Nodes/Roles/RoleBaselines/GatewayRoleBaseline.php` — gateway special handling.
- `app/Services/Nodes/Roles/RoleBaselines/AppDevelopmentRoleBaseline.php` — development host baseline.
- `app/Services/Nodes/Roles/RoleBaselines/AppProductionRoleBaseline.php` — production host baseline.
- `app/Services/Nodes/Roles/RoleBaselines/DatabaseRoleBaseline.php` — database host readiness baseline.
- `app/Console/Commands/NodeRoleListCommand.php`
- `app/Console/Commands/NodeRoleAddCommand.php`
- `app/Console/Commands/NodeRoleUpdateCommand.php`
- `app/Console/Commands/NodeRoleRemoveCommand.php`
- `app/Http/Gateway/Requests/Nodes/ListNodeRolesRequest.php`
- `app/Http/Gateway/Requests/Nodes/AddNodeRoleRequest.php`
- `app/Http/Gateway/Requests/Nodes/UpdateNodeRoleRequest.php`
- `app/Http/Gateway/Requests/Nodes/RemoveNodeRoleRequest.php`
- `app/Http/Gateway/Responses/Nodes/NodeRoleListResponse.php`
- `app/Http/Gateway/Responses/Nodes/NodeRoleMutationResponse.php`
- `app/Http/Controllers/Api/NodeRoleListController.php`
- `app/Http/Controllers/Api/NodeRoleAddController.php`
- `app/Http/Controllers/Api/NodeRoleUpdateController.php`
- `app/Http/Controllers/Api/NodeRoleRemoveController.php`
- `docs/commands/1_node/11_node-role-list/node-role-list.md`
- `docs/commands/1_node/11_node-role-list/technical/1_node-role-list.md`
- `docs/commands/1_node/11_node-role-list/technical/6.1_node-role-list_output-render_human.md`
- `docs/commands/1_node/11_node-role-list/technical/6.2_node-role-list_output-render_json.md`
- `docs/commands/1_node/12_node-role-add/node-role-add.md`
- `docs/commands/1_node/12_node-role-add/technical/1_node-role-add.md`
- `docs/commands/1_node/12_node-role-add/technical/5.1_node-role-add_input-mode_interactive.md`
- `docs/commands/1_node/12_node-role-add/technical/5.2_node-role-add_input-mode_non-interactive.md`
- `docs/commands/1_node/12_node-role-add/technical/6.1_node-role-add_output-render_human.md`
- `docs/commands/1_node/12_node-role-add/technical/6.2_node-role-add_output-render_json.md`
- `docs/commands/1_node/13_node-role-update/node-role-update.md`
- `docs/commands/1_node/13_node-role-update/technical/1_node-role-update.md`
- `docs/commands/1_node/13_node-role-update/technical/5.1_node-role-update_input-mode_interactive.md`
- `docs/commands/1_node/13_node-role-update/technical/5.2_node-role-update_input-mode_non-interactive.md`
- `docs/commands/1_node/13_node-role-update/technical/6.1_node-role-update_output-render_human.md`
- `docs/commands/1_node/13_node-role-update/technical/6.2_node-role-update_output-render_json.md`
- `docs/commands/1_node/14_node-role-remove/node-role-remove.md`
- `docs/commands/1_node/14_node-role-remove/technical/1_node-role-remove.md`
- `docs/commands/1_node/14_node-role-remove/technical/5.1_node-role-remove_input-mode_interactive.md`
- `docs/commands/1_node/14_node-role-remove/technical/5.2_node-role-remove_input-mode_non-interactive.md`
- `docs/commands/1_node/14_node-role-remove/technical/6.1_node-role-remove_output-render_human.md`
- `docs/commands/1_node/14_node-role-remove/technical/6.2_node-role-remove_output-render_json.md`

### Modified Files

- `docs/ARCHITECTURE.md` — replace control/app role language with gateway, joined client identity, hosted node roles, and grants.
- `docs/CONCEPTS.md` — add new node concepts to the concept index.
- `docs/BUILDING-BLOCKS.md` — update platform/role and Docker Compose backing-service language.
- `docs/commands/1_node/README.md` — authoritative role model rewrite.
- `docs/commands/1_node/node-concepts.md` — new vocabulary and platform support matrix.
- `docs/commands/1_node/node-doctor.md` — role assignment, settings, status, and baseline drift contract.
- `docs/commands/1_node/technical/node-doctor.md` — technical mirror of doctor behavior.
- Existing node command docs under `docs/commands/1_node/**` — replace `control/app` caller-role product language where the command behavior changes.
- `database/migrations/*nodes*` — leave existing columns in place for compatibility during v1; add follow-up migration to backfill `node_roles`.
- `app/Models/Node.php` — add relationships and helpers.
- `database/factories/NodeFactory.php` — no longer implies app hosting by default.
- `app/Console/Commands/NodeNewCommand.php` — split node identity creation from initial role assignment; allow repeated `--role`.
- `app/Console/Commands/NodeListCommand.php`
- `app/Console/Commands/NodeShowCommand.php`
- `app/Console/Commands/NodeDefaultCommand.php`
- `app/Console/Commands/NodeUpdateCommand.php`
- `app/Http/Controllers/Api/MeController.php`
- `app/Http/Controllers/Api/NodeListController.php`
- `app/Http/Controllers/Api/NodeShowController.php`
- `app/Http/Controllers/Api/NodeStoreController.php`
- `app/Http/Controllers/Api/NodeUpdateController.php`
- `app/Http/Controllers/Api/NodeDefaultController.php`
- `app/Http/Controllers/Api/AppStoreController.php`
- `app/Services/Apps/AppsProbe.php`
- `app/Services/Tools/ToolInstaller.php`
- `app/Services/Nodes/NodesProbe.php`
- `app/Services/Nodes/NodeRegistryWriter.php`
- `routes/api.php`

### Tests

- `tests/Feature/Commands/Nodes/NodeRoleListCommandTest.php`
- `tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php`
- `tests/Feature/Commands/Nodes/NodeRoleUpdateCommandTest.php`
- `tests/Feature/Commands/Nodes/NodeRoleRemoveCommandTest.php`
- `tests/Feature/Commands/Nodes/NodeRoleJsonRendererTest.php`
- `tests/Feature/Commands/Nodes/NodeNewComposableRolesTest.php`
- `tests/Feature/Commands/Nodes/NodeListComposableRolesTest.php`
- `tests/Feature/Commands/Nodes/NodeShowComposableRolesTest.php`
- `tests/Feature/Commands/Nodes/NodeDefaultComposableRolesTest.php`
- `tests/Feature/Commands/Apps/AppStoreNodeRoleEligibilityTest.php`
- `tests/Feature/Commands/Tools/ToolInstallNodeRoleEligibilityTest.php`
- `tests/Unit/Services/Nodes/NodeRoleRegistryTest.php`
- `tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php`
- `tests/Unit/Services/Nodes/NodesProbeRoleAssignmentsTest.php`

---

## Task 1: Align Product Docs

**Files:**
- Modify: `docs/ARCHITECTURE.md`
- Modify: `docs/CONCEPTS.md`
- Modify: `docs/BUILDING-BLOCKS.md`
- Modify: `docs/commands/1_node/README.md`
- Modify: `docs/commands/1_node/node-concepts.md`
- Modify: `docs/commands/1_node/node-doctor.md`
- Modify: `docs/commands/1_node/technical/node-doctor.md`

- [ ] **Step 1: Rewrite node role vocabulary**

Replace the current "Orbit has three node roles" language with this contract:

```markdown
Orbit distinguishes three concepts:

- **Gateway role:** the singleton authority role. It owns durable Orbit state,
  the typed API, WireGuard coordination, certificate authority material,
  development DNS coordination, grants, and doctor convergence. A gateway role
  assignment is stored in the role assignment model but cannot be added through
  normal role commands and conflicts with every hosted role.
- **Hosted node roles:** composable roles that prepare a node to serve a kind
  of workload. The initial hosted roles are `app-development`,
  `app-production`, and `database`.
- **Joined client identity:** a CLI installation that has gateway configuration
  and a gateway-issued WireGuard identity. A joined client may have no hosted
  roles. It can request self-scoped actions and can operate other nodes only
  through explicit gateway grants.
```

- [ ] **Step 2: Document role compatibility**

Add the compatibility matrix from this plan to `docs/commands/1_node/README.md`
and `docs/commands/1_node/node-concepts.md`.

- [ ] **Step 3: Document role settings**

Add this rule to `node-concepts.md`:

```markdown
Role-local desired configuration lives on the role assignment, not on the
generic node record. Each role assignment has typed settings:

- `app-development`: `tld`
- `app-production`: no settings in v1
- `database`: no settings in v1
- `gateway`: no settings in v1

Changing role settings is a desired-state change and triggers the same baseline
convergence path as adding the role.
```

- [ ] **Step 4: Document role assignment status**

Add `pending`, `active`, `error`, and `removing` status definitions to
`node-concepts.md` and node doctor docs. State that eligibility checks only use
`active` assignments.

- [ ] **Step 5: Document removal semantics**

Add this removal contract to `README.md` and `node-doctor.md`:

```markdown
`node role:remove` blocks when dependents exist.
`node role:remove --force` removes Orbit-owned dependents and role-owned
configuration while preserving user data.
`node role:remove --force --purge-data` also deletes role-owned data for
resources whose command contract explicitly supports purging.
```

- [ ] **Step 6: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: concept indexes and command docs pass, or failures point to the
specific concept index entries that must be updated in this task.

- [ ] **Step 7: Commit**

```bash
git add docs/ARCHITECTURE.md docs/CONCEPTS.md docs/BUILDING-BLOCKS.md docs/commands/1_node
git commit -m "docs: define composable node roles"
```

---

## Task 2: Add Role Assignment Data Model

**Files:**
- Create: `database/migrations/2026_05_17_000000_create_node_roles_table.php`
- Create: `app/Models/NodeRoleAssignment.php`
- Create: `database/factories/NodeRoleAssignmentFactory.php`
- Modify: `app/Models/Node.php`
- Modify: `database/factories/NodeFactory.php`
- Test: `tests/Feature/Commands/Nodes/NodeRoleDataModelTest.php`

- [ ] **Step 1: Write failing model test**

Create `tests/Feature/Commands/Nodes/NodeRoleDataModelTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores multiple role assignments with typed status and settings per node', function (): void {
    $node = Node::factory()->create(['name' => 'dev-1', 'role' => 'control']);

    NodeRoleAssignment::query()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    NodeRoleAssignment::query()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => 'active',
        'settings' => [],
    ]);

    expect($node->fresh()->roleAssignments)
        ->toHaveCount(2)
        ->and($node->fresh()->roleAssignments->pluck('role')->all())
        ->toBe(['app-development', 'database']);
});

it('enforces one assignment per role per node', function (): void {
    $node = Node::factory()->create();

    NodeRoleAssignment::query()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => 'active',
        'settings' => [],
    ]);

    expect(fn () => NodeRoleAssignment::query()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => 'active',
        'settings' => [],
    ]))->toThrow(Throwable::class);
});
```

- [ ] **Step 2: Run failing test**

```bash
php artisan test --compact tests/Feature/Commands/Nodes/NodeRoleDataModelTest.php
```

Expected: fails because `node_roles` and `NodeRoleAssignment` do not exist.

- [ ] **Step 3: Create migration**

Create `database/migrations/2026_05_17_000000_create_node_roles_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->string('role');
            $table->string('status')->default('pending');
            $table->json('settings')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('converged_at')->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'role']);
            $table->index(['role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_roles');
    }
};
```

- [ ] **Step 4: Create model**

Create `app/Models/NodeRoleAssignment.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NodeRoleAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $node_id
 * @property string $role
 * @property string $status
 * @property array<string, mixed>|null $settings
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $converged_at
 * @property-read Node|null $node
 */
class NodeRoleAssignment extends Model
{
    /** @use HasFactory<NodeRoleAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'node_id',
        'role',
        'status',
        'settings',
        'last_error',
        'converged_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'converged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
```

- [ ] **Step 5: Add Node relationship**

Add to `app/Models/Node.php`:

```php
/**
 * @return HasMany<NodeRoleAssignment, $this>
 */
public function roleAssignments(): HasMany
{
    return $this->hasMany(NodeRoleAssignment::class)->orderBy('role');
}

public function hasActiveRole(string $role): bool
{
    if (! $this->relationLoaded('roleAssignments')) {
        return $this->roleAssignments()
            ->where('role', $role)
            ->where('status', 'active')
            ->exists();
    }

    return $this->roleAssignments
        ->contains(fn (NodeRoleAssignment $assignment): bool => $assignment->role === $role && $assignment->status === 'active');
}
```

- [ ] **Step 6: Add factory**

Create `database/factories/NodeRoleAssignmentFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NodeRoleAssignment>
 */
class NodeRoleAssignmentFactory extends Factory
{
    protected $model = NodeRoleAssignment::class;

    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'role' => 'database',
            'status' => 'active',
            'settings' => [],
            'last_error' => null,
            'converged_at' => now(),
        ];
    }
}
```

- [ ] **Step 7: Run test**

```bash
php artisan test --compact tests/Feature/Commands/Nodes/NodeRoleDataModelTest.php
```

Expected: pass.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_05_17_000000_create_node_roles_table.php app/Models/Node.php app/Models/NodeRoleAssignment.php database/factories/NodeRoleAssignmentFactory.php database/factories/NodeFactory.php tests/Feature/Commands/Nodes/NodeRoleDataModelTest.php
git commit -m "feat: add node role assignments"
```

---

## Task 3: Add Code-Defined Role Registry

**Files:**
- Create: `app/Enums/Nodes/NodeRoleName.php`
- Create: `app/Enums/Nodes/NodeRoleStatus.php`
- Create: `app/Data/Nodes/RoleSettings/NodeRoleSettings.php`
- Create: `app/Data/Nodes/RoleSettings/EmptyRoleSettings.php`
- Create: `app/Data/Nodes/RoleSettings/AppDevelopmentRoleSettings.php`
- Create: `app/Data/Nodes/RoleSettings/AppProductionRoleSettings.php`
- Create: `app/Data/Nodes/RoleSettings/DatabaseRoleSettings.php`
- Create: `app/Services/Nodes/Roles/NodeRoleDefinition.php`
- Create: `app/Services/Nodes/Roles/NodeRoleRegistry.php`
- Test: `tests/Unit/Services/Nodes/NodeRoleRegistryTest.php`

- [ ] **Step 1: Write failing registry tests**

Create `tests/Unit/Services/Nodes/NodeRoleRegistryTest.php`:

```php
<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\AppDevelopmentRoleSettings;
use App\Services\Nodes\Roles\NodeRoleRegistry;

it('defines the initial role compatibility matrix', function (): void {
    $registry = new NodeRoleRegistry;

    expect($registry->definition('gateway')->conflictsWith)->toBe([
        'app-development',
        'app-production',
        'database',
    ]);

    expect($registry->definition('app-development')->conflictsWith)->toBe([
        'gateway',
        'app-production',
    ]);

    expect($registry->definition('app-production')->conflictsWith)->toBe([
        'gateway',
        'app-development',
    ]);

    expect($registry->definition('database')->conflictsWith)->toBe([
        'gateway',
    ]);
});

it('hydrates role-specific settings DTOs', function (): void {
    $settings = (new NodeRoleRegistry)
        ->definition('app-development')
        ->settingsFromArray(['tld' => 'test']);

    expect($settings)
        ->toBeInstanceOf(AppDevelopmentRoleSettings::class)
        ->and($settings->toArray())
        ->toBe(['tld' => 'test']);
});

it('rejects invalid app development settings', function (): void {
    expect(fn () => (new NodeRoleRegistry)
        ->definition('app-development')
        ->settingsFromArray(['tld' => '']))
        ->toThrow(InvalidArgumentException::class, 'The app-development role requires a non-empty tld setting.');
});
```

- [ ] **Step 2: Run failing test**

```bash
php artisan test --compact tests/Unit/Services/Nodes/NodeRoleRegistryTest.php
```

Expected: fails because registry classes do not exist.

- [ ] **Step 3: Implement role enums**

Create `app/Enums/Nodes/NodeRoleName.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums\Nodes;

enum NodeRoleName: string
{
    case Gateway = 'gateway';
    case AppDevelopment = 'app-development';
    case AppProduction = 'app-production';
    case Database = 'database';
}
```

Create `app/Enums/Nodes/NodeRoleStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums\Nodes;

enum NodeRoleStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Error = 'error';
    case Removing = 'removing';
}
```

- [ ] **Step 4: Implement settings DTOs**

Create `app/Data/Nodes/RoleSettings/NodeRoleSettings.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

interface NodeRoleSettings
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
```

Create `app/Data/Nodes/RoleSettings/AppDevelopmentRoleSettings.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

use InvalidArgumentException;

final readonly class AppDevelopmentRoleSettings implements NodeRoleSettings
{
    public function __construct(public string $tld)
    {
        if (trim($this->tld) === '') {
            throw new InvalidArgumentException('The app-development role requires a non-empty tld setting.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        $tld = $settings['tld'] ?? null;

        if (! is_string($tld)) {
            throw new InvalidArgumentException('The app-development role requires a non-empty tld setting.');
        }

        return new self($tld);
    }

    #[\Override]
    public function toArray(): array
    {
        return ['tld' => $this->tld];
    }
}
```

Create empty settings classes for `gateway`, `app-production`, and `database` using:

```php
<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

final readonly class EmptyRoleSettings implements NodeRoleSettings
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        if ($settings !== []) {
            throw new \InvalidArgumentException('This role does not accept settings.');
        }

        return new self;
    }

    #[\Override]
    public function toArray(): array
    {
        return [];
    }
}
```

`AppProductionRoleSettings` and `DatabaseRoleSettings` can extend the same behavior by duplicating the small class body with role-specific error messages.

- [ ] **Step 5: Implement role definition and registry**

Create `app/Services/Nodes/Roles/NodeRoleDefinition.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Data\Nodes\RoleSettings\NodeRoleSettings;

final readonly class NodeRoleDefinition
{
    /**
     * @param  list<string>  $conflictsWith
     * @param  list<string>  $supportedPlatforms
     * @param  class-string<NodeRoleSettings>  $settingsClass
     */
    public function __construct(
        public string $name,
        public array $conflictsWith,
        public array $supportedPlatforms,
        public string $settingsClass,
        public bool $assignableByCommand = true,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public function settingsFromArray(array $settings): NodeRoleSettings
    {
        return $this->settingsClass::fromArray($settings);
    }
}
```

Create `app/Services/Nodes/Roles/NodeRoleRegistry.php` with definitions:

```php
new NodeRoleDefinition(
    name: 'gateway',
    conflictsWith: ['app-development', 'app-production', 'database'],
    supportedPlatforms: ['ubuntu'],
    settingsClass: EmptyRoleSettings::class,
    assignableByCommand: false,
);
```

Use:
- `app-development`: conflicts `['gateway', 'app-production']`, supported platforms `['ubuntu']`, settings `AppDevelopmentRoleSettings::class`.
- `app-production`: conflicts `['gateway', 'app-development']`, supported platforms `['ubuntu']`, settings `AppProductionRoleSettings::class`.
- `database`: conflicts `['gateway']`, supported platforms `['ubuntu']`, settings `DatabaseRoleSettings::class`.

- [ ] **Step 6: Run registry tests**

```bash
php artisan test --compact tests/Unit/Services/Nodes/NodeRoleRegistryTest.php
```

Expected: pass.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/Nodes app/Data/Nodes/RoleSettings app/Services/Nodes/Roles tests/Unit/Services/Nodes/NodeRoleRegistryTest.php
git commit -m "feat: define node role registry"
```

---

## Task 4: Implement Role Assignment Service

**Files:**
- Create: `app/Services/Nodes/Roles/NodeRoleAssignments.php`
- Create: `app/Services/Nodes/Roles/NodeRoleAssignmentService.php`
- Create: `app/Services/Nodes/Roles/NodeRoleBaselineConverger.php`
- Create: `app/Services/Nodes/Roles/NodeRoleDependencyInspector.php`
- Create: `app/Services/Nodes/Roles/RoleBaselines/RoleBaseline.php`
- Create: role baseline classes under `app/Services/Nodes/Roles/RoleBaselines/`
- Test: `tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php`

- [ ] **Step 1: Write failing service tests**

Create `tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\NodeRoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('activates a compatible role after convergence succeeds', function (): void {
    $node = Node::factory()->create(['platform' => 'ubuntu']);
    $service = app(NodeRoleAssignmentService::class);

    $assignment = $service->add($node, 'database', []);

    expect($assignment->status)
        ->toBe('active')
        ->and($assignment->role)
        ->toBe('database')
        ->and($assignment->converged_at)
        ->not->toBeNull();
});

it('rejects conflicting roles', function (): void {
    $node = Node::factory()->create(['platform' => 'ubuntu']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-production', []))
        ->toThrow(InvalidArgumentException::class, "Role 'app-production' conflicts with active role 'app-development'.");
});

it('marks role as error when convergence fails', function (): void {
    $node = Node::factory()->create(['platform' => 'ubuntu']);
    $converger = new class extends \App\Services\Nodes\Roles\NodeRoleBaselineConverger {
        public function converge(Node $node, NodeRoleAssignment $assignment): void
        {
            throw new RuntimeException('Docker is missing.');
        }
    };

    app()->instance(\App\Services\Nodes\Roles\NodeRoleBaselineConverger::class, $converger);

    $assignment = app(NodeRoleAssignmentService::class)->add($node, 'database', []);

    expect($assignment->status)
        ->toBe('error')
        ->and($assignment->last_error)
        ->toBe('Docker is missing.');
});
```

- [ ] **Step 2: Implement baseline interface**

Create `RoleBaseline`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeRoleAssignment;

interface RoleBaseline
{
    public function converge(Node $node, NodeRoleAssignment $assignment): void;

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void;
}
```

- [ ] **Step 3: Implement v1 baselines**

For v1:
- `AppDevelopmentRoleBaseline` validates `settings.tld` and calls existing development DNS reconciler after assignment is active.
- `AppProductionRoleBaseline` delegates to the existing app-node runtime/network baseline path used by `node:new`.
- `DatabaseRoleBaseline` verifies the node is not gateway, has an Ubuntu platform record, and ensures Docker is a desired node tool if the existing tool model already has `docker`; it does not install Postgres/MySQL by itself.
- `GatewayRoleBaseline` is only used by bootstrap/backfill and fails if called through normal command assignment.

- [ ] **Step 4: Implement service**

`NodeRoleAssignmentService::add(Node $node, string $role, array $settings): NodeRoleAssignment` must:
- validate role exists;
- reject non-command-assignable roles for CLI assignment;
- validate platform from the role definition;
- hydrate and persist settings through DTO;
- validate conflicts against active, pending, and error assignments;
- write `pending`;
- synchronously converge;
- mark `active` on success;
- mark `error` with `last_error` on failure.

`update()` must:
- validate role exists on node;
- validate new settings through DTO;
- persist settings and set `pending`;
- converge synchronously;
- mark `active` or `error`.

`remove()` must:
- inspect dependents;
- block without `--force`;
- set `removing`;
- remove Orbit-owned dependents for `--force`;
- call baseline cleanup with `purgeData`;
- delete the assignment only after cleanup succeeds;
- leave `error` with `last_error` when cleanup fails.

- [ ] **Step 5: Run service tests**

```bash
php artisan test --compact tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php
```

Expected: pass.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Nodes/Roles tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php
git commit -m "feat: manage node role assignments"
```

---

## Task 5: Backfill Existing Nodes

**Files:**
- Create: `database/migrations/2026_05_17_000001_backfill_node_roles_from_legacy_nodes.php`
- Test: `tests/Feature/Commands/Nodes/NodeRoleBackfillTest.php`

- [ ] **Step 1: Write failing migration test**

Create `tests/Feature/Commands/Nodes/NodeRoleBackfillTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('backfills legacy gateway control and app nodes to role assignments', function (): void {
    $gateway = Node::factory()->create(['role' => 'gateway', 'environment' => null]);
    $control = Node::factory()->create(['role' => 'control', 'environment' => null]);
    $dev = Node::factory()->create(['role' => 'app', 'environment' => 'development', 'tld' => 'test']);
    $prod = Node::factory()->create(['role' => 'app', 'environment' => 'production', 'tld' => null]);

    $this->artisan('migrate');

    expect(NodeRoleAssignment::query()->where('node_id', $gateway->id)->where('role', 'gateway')->exists())->toBeTrue()
        ->and(NodeRoleAssignment::query()->where('node_id', $control->id)->exists())->toBeFalse()
        ->and(NodeRoleAssignment::query()->where('node_id', $dev->id)->where('role', 'app-development')->value('settings'))->toBe(['tld' => 'test'])
        ->and(NodeRoleAssignment::query()->where('node_id', $prod->id)->where('role', 'app-production')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Implement migration**

Backfill:
- `role=gateway` → `node_roles.role=gateway`, `status=active`, `settings=[]`.
- `role=control` → no hosted role assignment.
- `role=app, environment=development` → `app-development`, `status=active`, `settings={"tld": nodes.tld}`.
- `role=app, environment=production` → `app-production`, `status=active`, `settings=[]`.

Do not drop `nodes.role`, `nodes.environment`, or `nodes.tld` in this task. They remain compatibility columns until every read path has moved.

- [ ] **Step 3: Run migration test**

```bash
php artisan test --compact tests/Feature/Commands/Nodes/NodeRoleBackfillTest.php
```

Expected: pass.

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_05_17_000001_backfill_node_roles_from_legacy_nodes.php tests/Feature/Commands/Nodes/NodeRoleBackfillTest.php
git commit -m "feat: backfill composable node roles"
```

---

## Task 6: Expose Roles In Read Paths

**Files:**
- Modify: `app/Console/Commands/NodeListCommand.php`
- Modify: `app/Console/Commands/NodeShowCommand.php`
- Modify: `app/Http/Controllers/Api/MeController.php`
- Modify: `app/Http/Controllers/Api/NodeListController.php`
- Modify: `app/Http/Controllers/Api/NodeShowController.php`
- Modify: `app/Http/Gateway/Responses/Nodes/NodeListResponse.php`
- Modify: `app/Http/Gateway/Responses/Nodes/NodeShowResponse.php`
- Test: `tests/Feature/Commands/Nodes/NodeListComposableRolesTest.php`
- Test: `tests/Feature/Commands/Nodes/NodeShowComposableRolesTest.php`

- [ ] **Step 1: Write failing node list/show tests**

Assert JSON includes both legacy `role` for compatibility and new `roles`:

```php
expect($payload['success']['data']['nodes'][0]['roles'])->toBe([
    [
        'role' => 'app-development',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ],
    [
        'role' => 'database',
        'status' => 'active',
        'settings' => [],
    ],
]);
```

Human renderers should show roles as `app-development, database` and show non-active statuses as `database (error)`.

- [ ] **Step 2: Update API serialization**

Every node serializer should eager load `roleAssignments` and include:

```php
'roles' => $node->roleAssignments
    ->map(fn (NodeRoleAssignment $assignment): array => [
        'role' => $assignment->role,
        'status' => $assignment->status,
        'settings' => $assignment->settings ?? [],
    ])
    ->values()
    ->all(),
```

- [ ] **Step 3: Keep legacy fields stable**

For v1 compatibility:
- `role` remains present.
- `environment` remains present for old consumers.
- new code must prefer `roles`.

- [ ] **Step 4: Run read path tests**

```bash
php artisan test --compact tests/Feature/Commands/Nodes/NodeListComposableRolesTest.php tests/Feature/Commands/Nodes/NodeShowComposableRolesTest.php
```

Expected: pass.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/NodeListCommand.php app/Console/Commands/NodeShowCommand.php app/Http/Controllers/Api/MeController.php app/Http/Controllers/Api/NodeListController.php app/Http/Controllers/Api/NodeShowController.php app/Http/Gateway/Responses/Nodes tests/Feature/Commands/Nodes/NodeListComposableRolesTest.php tests/Feature/Commands/Nodes/NodeShowComposableRolesTest.php
git commit -m "feat: expose node role assignments"
```

---

## Task 7: Add `node role:*` Commands

**Files:**
- Create: command docs under `docs/commands/1_node/11_node-role-list`, `12_node-role-add`, `13_node-role-update`, `14_node-role-remove`
- Create: command classes and gateway request/response/controller classes listed in File Map
- Modify: `routes/api.php`
- Test: `tests/Feature/Commands/Nodes/NodeRoleListCommandTest.php`
- Test: `tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php`
- Test: `tests/Feature/Commands/Nodes/NodeRoleUpdateCommandTest.php`
- Test: `tests/Feature/Commands/Nodes/NodeRoleRemoveCommandTest.php`
- Test: `tests/Feature/Commands/Nodes/NodeRoleJsonRendererTest.php`

- [ ] **Step 1: Write command docs**

Document these signatures:

```text
orbit node role:list {node?} [--json]
orbit node role:add {node} {role} [--tld=] [--json]
orbit node role:update {node} {role} [--tld=] [--json]
orbit node role:remove {node} {role} [--force] [--purge-data] [--json]
```

Rules:
- `--json` forces non-interactive mode.
- `--force` is destructive consent for Orbit-owned dependent cleanup.
- `--purge-data` requires `--force`; without it return `validation_failed`.
- `gateway` role cannot be added, updated, or removed through these commands.
- `app-development` requires `--tld`.
- `app-production` and `database` reject role-specific options they do not support.

- [ ] **Step 2: Write failing tests**

Cover:
- list roles for one node.
- add `database` to app-production node.
- add `app-development --tld=test` to joined client node with no hosted roles.
- reject `app-production` on a node with active `app-development`.
- reject `node role:add <node> gateway`.
- update app-development TLD and trigger convergence.
- remove blocks with dependents.
- `--force` preserves data.
- `--force --purge-data` calls purge cleanup.
- JSON success envelope has one top-level `success`.
- JSON failure envelope has one top-level `error`.

- [ ] **Step 3: Implement commands**

Follow existing node command patterns:
- control callers forward to gateway.
- gateway callers execute service locally.
- app/development/production/database hosted nodes are just callers; gateway authorization decides action eligibility.

Human output for `node role:add` and `node role:update` must render a progress tree because role convergence can be slow:

```text
Adding Node Role
  ✓ Resolve node dev-1
  ✓ Validate role app-development
  ✓ Store desired role pending
  ✓ Converge role baseline active
```

- [ ] **Step 4: Implement API controllers**

Controllers should:
- resolve caller from WireGuard identity;
- reject unknown callers;
- authorize through existing gateway/node grant pattern;
- call `NodeRoleAssignmentService`;
- emit activity log actions:
  - `node.role.added`
  - `node.role.updated`
  - `node.role.removed`
  - `node.role.remove_blocked`

- [ ] **Step 5: Run command tests**

```bash
php artisan test --compact tests/Feature/Commands/Nodes/NodeRoleListCommandTest.php tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php tests/Feature/Commands/Nodes/NodeRoleUpdateCommandTest.php tests/Feature/Commands/Nodes/NodeRoleRemoveCommandTest.php tests/Feature/Commands/Nodes/NodeRoleJsonRendererTest.php
```

Expected: pass.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add docs/commands/1_node/11_node-role-list docs/commands/1_node/12_node-role-add docs/commands/1_node/13_node-role-update docs/commands/1_node/14_node-role-remove app/Console/Commands/NodeRole* app/Http/Gateway/Requests/Nodes/*NodeRole* app/Http/Gateway/Responses/Nodes/*NodeRole* app/Http/Controllers/Api/NodeRole* routes/api.php tests/Feature/Commands/Nodes/NodeRole*CommandTest.php tests/Feature/Commands/Nodes/NodeRoleJsonRendererTest.php
git commit -m "feat: add node role commands"
```

---

## Task 8: Update Node Creation To Separate Identity And Roles

**Files:**
- Modify: `app/Console/Commands/NodeNewCommand.php`
- Modify: `app/Http/Controllers/Api/NodeStoreController.php`
- Modify: `app/Http/Gateway/Requests/Nodes/CreateNodeRequest.php`
- Modify: `app/Http/Gateway/Responses/Nodes/NodeCreateResponse.php`
- Modify: `app/Services/Nodes/NodeRegistryWriter.php`
- Test: `tests/Feature/Commands/Nodes/NodeNewComposableRolesTest.php`

- [ ] **Step 1: Write failing node:new tests**

Cover:
- `node:new client-1` creates a joined/client identity with no hosted roles.
- `node:new dev-1 --role=app-development --tld=test` creates identity and active role assignment.
- `node:new web-1 --role=app-production --role=database` creates two compatible role assignments.
- `node:new bad --role=app-development --role=app-production` fails before side effects.
- first gateway bootstrap still creates exactly one `gateway` role assignment.

- [ ] **Step 2: Update command signature**

Change role option description to:

```php
{--role=* : Initial hosted role. Repeatable: app-development, app-production, database. Gateway bootstrap uses gateway internally.}
{--tld= : App-development role TLD}
```

Keep accepting legacy values for one compatibility cycle:
- `--role=control` maps to no hosted role.
- `--role=app --environment=development` maps to `app-development`.
- `--role=app --environment=production` maps to `app-production`.

When a legacy value is used in human mode, render a warning:

```text
Legacy role value 'app' was mapped to 'app-development'. Use --role=app-development next time.
```

- [ ] **Step 3: Update node writer**

`NodeRegistryWriter` should write base node identity fields without deciding hosted role. Add method:

```php
public function writeNodeIdentity(
    string $name,
    string $host,
    string $wireguardAddress,
    ?string $gatewayEndpoint,
    string $user,
    string $platform = 'unknown',
): Node
```

The method should set legacy `role` conservatively:
- `gateway` for gateway bootstrap.
- `control` for nodes with no active hosted role at creation.
- `app` only while compatibility fields are still needed for existing app code, and only when initial app role is present.

- [ ] **Step 4: Delegate initial roles to service**

After node identity is written, call `NodeRoleAssignmentService::add()` for each requested role. If any assignment fails, fail the command and leave the failed role assignment in `error` so doctor can retry.

- [ ] **Step 5: Run node:new tests**

```bash
php artisan test --compact tests/Feature/Commands/Nodes/NodeNewComposableRolesTest.php
```

Expected: pass.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/NodeNewCommand.php app/Http/Controllers/Api/NodeStoreController.php app/Http/Gateway/Requests/Nodes/CreateNodeRequest.php app/Http/Gateway/Responses/Nodes/NodeCreateResponse.php app/Services/Nodes/NodeRegistryWriter.php tests/Feature/Commands/Nodes/NodeNewComposableRolesTest.php
git commit -m "feat: create nodes with hosted roles"
```

---

## Task 9: Replace App Node Eligibility Checks

**Files:**
- Modify: `app/Http/Controllers/Api/AppStoreController.php`
- Modify: `app/Http/Controllers/Api/NodeDefaultController.php`
- Modify: `app/Console/Commands/NodeDefaultCommand.php`
- Modify: `app/Services/Apps/AppsProbe.php`
- Modify: app/workspace/process controllers that query `nodes.role = app`
- Test: `tests/Feature/Commands/Apps/AppStoreNodeRoleEligibilityTest.php`
- Test: `tests/Feature/Commands/Nodes/NodeDefaultComposableRolesTest.php`

- [ ] **Step 1: Write failing eligibility tests**

App creation:
- development app accepts node with active `app-development`.
- production app accepts node with active `app-production`.
- app creation rejects node with only `database`.
- app creation rejects node where role assignment is `pending`, `error`, or `removing`.

Node default:
- only active `app-development` nodes can become local default.
- `database` and `app-production` nodes are rejected.

- [ ] **Step 2: Add query helper**

Use `NodeRoleAssignments` with methods:

```php
public function nodeHasActiveRole(Node $node, string $role): bool;

/**
 * @return list<int>
 */
public function activeNodeIdsForRole(string $role): array;
```

- [ ] **Step 3: Replace direct role checks**

Replace:

```php
->where('nodes.role', 'app')
```

with role-assignment joins for specific capabilities:

```php
->whereHas('roleAssignments', fn (Builder $query): Builder => $query
    ->where('role', 'app-development')
    ->where('status', 'active'))
```

Use `app-production` where production app hosting is required.

- [ ] **Step 4: Keep compatibility fields updated**

When adding/removing `app-development` or `app-production`, update legacy `nodes.role`, `nodes.environment`, and `nodes.tld` only as compatibility shadows. Do not use them for new eligibility decisions.

- [ ] **Step 5: Run eligibility tests**

```bash
php artisan test --compact tests/Feature/Commands/Apps/AppStoreNodeRoleEligibilityTest.php tests/Feature/Commands/Nodes/NodeDefaultComposableRolesTest.php
```

Expected: pass.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/AppStoreController.php app/Http/Controllers/Api/NodeDefaultController.php app/Console/Commands/NodeDefaultCommand.php app/Services/Apps/AppsProbe.php app/Services/Nodes/Roles/NodeRoleAssignments.php tests/Feature/Commands/Apps/AppStoreNodeRoleEligibilityTest.php tests/Feature/Commands/Nodes/NodeDefaultComposableRolesTest.php
git commit -m "refactor: use active app host roles"
```

---

## Task 10: Require Database Role For Database Tools

**Files:**
- Modify: `app/Services/Tools/ToolInstaller.php`
- Modify: `app/Console/Commands/ToolInstallCommand.php`
- Modify: `docs/commands/3_tool/catalog/postgres.md`
- Modify: `docs/commands/3_tool/catalog/mysql.md`
- Test: `tests/Feature/Commands/Tools/ToolInstallNodeRoleEligibilityTest.php`

- [ ] **Step 1: Write failing tests**

Cover:
- `tool:install postgres --node=db-1` succeeds when `db-1` has active `database`.
- `tool:install mysql --node=web-1` fails when `web-1` has only `app-production`.
- `tool:install redis --node=web-1` keeps current behavior unless the tool catalog explicitly requires a role.

Expected error:

```json
{
  "error": {
    "code": "node.role_required",
    "message": "Tool 'postgres' requires node 'web-1' to have active role 'database'.",
    "meta": {
      "node": "web-1",
      "required_role": "database",
      "tool": "postgres"
    }
  }
}
```

- [ ] **Step 2: Add required role to tool catalog metadata**

Add a method to tool definitions:

```php
public function requiredNodeRole(): ?string
{
    return null;
}
```

Override for `PostgresTool` and `MysqlTool`:

```php
public function requiredNodeRole(): ?string
{
    return 'database';
}
```

- [ ] **Step 3: Validate in ToolInstaller**

After target node resolution and before writing `node_tools`, check:

```php
$requiredRole = $this->catalog->requiredNodeRole($tool);

if ($requiredRole !== null && ! $targetNode->hasActiveRole($requiredRole)) {
    return ToolRegistryFailure::validation(
        'node',
        $targetNode->name,
        "Tool '{$tool}' requires node '{$targetNode->name}' to have active role '{$requiredRole}'.",
        [
            'code' => 'node.role_required',
            'node' => $targetNode->name,
            'required_role' => $requiredRole,
            'tool' => $tool,
        ],
    );
}
```

Adjust `ToolRegistryFailure` if it cannot currently carry a custom code.

- [ ] **Step 4: Update tool docs**

Document:
- `postgres` and `mysql` require `database`.
- app database selection is still not implemented here.
- installing database tools on an app node is allowed only when that same node also has `database`.

- [ ] **Step 5: Run tool tests**

```bash
php artisan test --compact tests/Feature/Commands/Tools/ToolInstallNodeRoleEligibilityTest.php
```

Expected: pass.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Tools app/Tools app/Console/Commands/ToolInstallCommand.php docs/commands/3_tool/catalog/postgres.md docs/commands/3_tool/catalog/mysql.md tests/Feature/Commands/Tools/ToolInstallNodeRoleEligibilityTest.php
git commit -m "feat: require database role for database tools"
```

---

## Task 11: Update Node Doctor For Role Assignments

**Files:**
- Modify: `app/Services/Nodes/NodesProbe.php`
- Modify: `docs/commands/1_node/node-doctor.md`
- Modify: `docs/commands/1_node/technical/node-doctor.md`
- Test: `tests/Unit/Services/Nodes/NodesProbeRoleAssignmentsTest.php`

- [ ] **Step 1: Write failing probe tests**

Cover drift:
- active node has no compatible role assignment but legacy role says app → `node.role_assignment_missing`.
- assignment has invalid role → `node.role_assignment_invalid`.
- assignment settings do not hydrate → `node.role_settings_invalid`.
- active role assignment conflicts with another active assignment → `node.role_conflict`.
- development role has no TLD → `node.role_settings_invalid`.
- role assignment status `error` → `node.role_convergence_failed`.

- [ ] **Step 2: Update docs issue codes**

Add:
- `node.role_assignment_missing`
- `node.role_assignment_invalid`
- `node.role_conflict`
- `node.role_settings_invalid`
- `node.role_convergence_failed`
- `node.role_baseline_mismatch`

- [ ] **Step 3: Implement probe checks**

`NodesProbe` should use `NodeRoleRegistry` and `NodeRoleAssignments` to validate role assignment rows before legacy fields.

Record completeness should shift from:

```php
($node->role === 'app' && empty($node->environment))
```

to:

```php
$this->roleAssignments->requiresHostedRoleFacts($node)
```

where facts are checked based on active role assignments and their settings.

- [ ] **Step 4: Implement restore behavior**

`doctor --family=node --restore` should:
- retry role baseline convergence for `error` assignments;
- restore role-owned settings-derived artifacts, such as development DNS mapping;
- not invent missing role assignments for unknown nodes.

- [ ] **Step 5: Run probe tests**

```bash
php artisan test --compact tests/Unit/Services/Nodes/NodesProbeRoleAssignmentsTest.php
```

Expected: pass.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Nodes/NodesProbe.php docs/commands/1_node/node-doctor.md docs/commands/1_node/technical/node-doctor.md tests/Unit/Services/Nodes/NodesProbeRoleAssignmentsTest.php
git commit -m "feat: check node role drift"
```

---

## Task 12: Final Compatibility Sweep

**Files:**
- Modify all files found by role/environment search.
- Test: existing node, app, workspace, process, tool suites.

- [ ] **Step 1: Search remaining single-role checks**

Run:

```bash
rg -n "role' => 'app'|role\" => \"app\"|where\\('role', 'app'\\)|where\\(\"role\", \"app\"\\)|caller_role|environment.*development|environment.*production|nodes\\.role" app tests docs
```

Classify each hit:
- caller authorization still may use authenticated legacy caller role until the API identity model is separately refactored;
- hosted capability checks must use `node_roles`;
- docs must use new role vocabulary.

- [ ] **Step 2: Update tests that build app nodes**

Factories and test helpers that create app nodes must also create active role assignments. Preferred helper:

```php
function createDevelopmentNode(array $nodeAttributes = [], array $settings = ['tld' => 'test']): Node
{
    $node = Node::factory()->create($nodeAttributes + [
        'role' => 'app',
        'environment' => 'development',
        'tld' => $settings['tld'],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => 'active',
        'settings' => $settings,
    ]);

    return $node;
}
```

- [ ] **Step 3: Run targeted suites**

```bash
php artisan test --compact tests/Feature/Commands/Nodes tests/Feature/Commands/Apps tests/Feature/Commands/Tools tests/Unit/Services/Nodes
```

Expected: pass.

- [ ] **Step 4: Run formatting**

```bash
vendor/bin/pint --dirty --format agent
```

Expected: Pint reports changed files or clean status.

- [ ] **Step 5: Run broad quality check**

```bash
composer quality-check
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app tests docs database
git commit -m "chore: finish composable node role migration"
```

---

## Implementation Notes

- Do not drop legacy `nodes.role`, `nodes.environment`, or `nodes.tld` in this plan. Keep them as compatibility shadows until a later cleanup plan can remove old command contracts safely.
- Do not represent self-operation as a `node_access` row. Self-scoped requests are allowed to reach gateway policy, and the gateway authorizes each action.
- Do not make `operator` a stored role. A node operates through joined identity plus grants.
- Do not let `--force` imply data deletion. `--purge-data` is the explicit destructive data flag and must require `--force`.
- Do not add role overrides. If two roles conflict, reject the combination and guide the operator to a separate node.
- Do not let non-active role assignments satisfy app/tool eligibility.

## Open Questions

None for this foundation slice. `file-storage` should be added by extending the code-defined registry and baseline interfaces after the role foundation is merged.
