# WebSocket Role Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a private `websocket` node role that runs Laravel Reverb behind router-owned service endpoints and app-owned public WebSocket bindings.

**Architecture:** Build on the router/ingress contract: ingress accepts public WSS hosts and forwards to router, router owns `websocket.orbit` and websocket backend pools, and websocket nodes run Reverb with Redis-backed scaling configuration. Apps receive per-app Reverb credentials and never target concrete websocket nodes.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 4, SQLite JSON role settings, encrypted Eloquent casts, Laravel Reverb, Redis tool on database nodes, Caddy route rendering, Docker-first runtime containers, Orbit CA certificates.

> **Repository layout:** Gateway code and tests live under `apps/gateway`.
> Unless a task explicitly targets root shims, `apps/cli`, `packages/core`,
> `bin`, or `docker`, use `apps/gateway/app`, `apps/gateway/database`,
> `apps/gateway/routes`, and `apps/gateway/tests`.
>
> **E2E contract:** Use `apps/docs/content/testing/README.md` and
> `apps/docs/content/testing/e2e/**` as authority. Docker E2E uses composable role images
> and runs the smallest requested topology from those images. Incus feature E2E
> uses prepared role snapshots from `orbit-base-ubuntu-26.04` and boots only the
> roles a test requests. Supported providers are Docker and Incus.

---

## Status

**Source spec:** `docs/superpowers/specs/2026-05-21-websocket-role-design.md`

**Required dependency:** The router/ingress contract in
`docs/superpowers/plans/2026-05-21-ingress-router-addendum.md` must land
before this plan is implemented. This plan assumes `router` exists as a visible
gateway-coupled role and ingress forwards public traffic to router.

**Out of scope:**

- Arbitrary non-Reverb WebSocket hosting.
- Public listeners on websocket nodes.
- Multi-node websocket autoscaling.
- Redis installation or ownership by the websocket role.
- TCP database service routing.

## File Map

### Product Docs

- Modify: `apps/docs/content/architecture.md` - add websocket role and router-backed realtime traffic shape.
- Modify: `apps/docs/content/tech-stack.md` - document Laravel Reverb runtime, TLS private bind, Redis dependency, and process manager.
- Modify: `apps/docs/content/concepts.md` - add `websocket` role, app websocket binding, websocket backend pool, and Reverb credential terms.
- Modify: `apps/docs/content/domains/1_node/node-concepts.md` - add role compatibility, settings, baseline, and platform support.
- Modify: `apps/docs/content/domains/1_node/1_node-new/**` - document `--role=websocket --redis-node=`.
- Modify: `apps/docs/content/domains/1_node/12_node-role-add/**` - document adding `websocket`.
- Modify: `apps/docs/content/domains/5_app/**` - document app websocket binding ownership.
- Modify: `apps/docs/content/domains/8_proxy/**` - document websocket route placement through router and ingress.
- Modify: `apps/docs/content/domains/3_tool/catalog/reverb.md` - align the current Reverb tool catalog with the role-owned Reverb runtime.

### Schema And Models

- Create: `apps/gateway/database/migrations/YYYY_MM_DD_HHMMSS_create_app_websocket_bindings_table.php`
- Create: `apps/gateway/app/Models/AppWebSocketBinding.php`
- Create: `apps/gateway/database/factories/AppWebSocketBindingFactory.php`
- Modify: `apps/gateway/app/Models/App.php`

### Role Model

- Modify: `apps/gateway/app/Enums/Nodes/NodeRoleName.php`
- Create: `apps/gateway/app/Data/Nodes/RoleSettings/WebSocketRoleSettings.php`
- Modify: `apps/gateway/app/Services/Nodes/Roles/NodeRoleRegistry.php`
- Modify: `apps/gateway/app/Services/Nodes/Roles/NodeRoleBaselineConverger.php`
- Create: `apps/gateway/app/Services/Nodes/Roles/RoleBaselines/WebSocketRoleBaseline.php`
- Modify: `apps/gateway/app/Services/Nodes/Roles/NodeRoleAssignmentService.php`

### Runtime And Certificates

- Create: `apps/gateway/app/Services/WebSockets/WebSocketRuntimeRenderer.php`
- Create: `apps/gateway/app/Services/WebSockets/WebSocketBackendName.php`
- Create: `apps/gateway/app/Services/WebSockets/WebSocketRedisResolver.php`
- Create: `apps/gateway/app/Services/WebSockets/WebSocketCertificateInstaller.php`
- Modify: `apps/gateway/app/Services/Ca/OrbitSiteCertificateInstaller.php` only if backend certificate helpers need a reusable method.

### Router, Proxy, And App Binding

- Create: `apps/gateway/app/Services/WebSockets/WebSocketBindingService.php`
- Create: `apps/gateway/app/Services/WebSockets/WebSocketCredentials.php`
- Create: `apps/gateway/app/Services/WebSockets/WebSocketRouteRegistrar.php`
- Modify: router route rendering services created by the ingress/router work.
- Modify: `apps/gateway/app/Services/Proxy/ProxyRouteRenderer.php` or the post-router equivalent to support WebSocket upstream options.

### Commands And API

- Create: `apps/gateway/app/Console/Commands/AppWebSocketEnableCommand.php`
- Create: `apps/gateway/app/Console/Commands/AppWebSocketCredentialsCommand.php`
- Create: `apps/gateway/app/Console/Commands/AppWebSocketDisableCommand.php`
- Create: `apps/gateway/app/Http/Gateway/Requests/Apps/EnableAppWebSocketRequest.php`
- Create: `apps/gateway/app/Http/Gateway/Requests/Apps/AppWebSocketCredentialsRequest.php`
- Create: `apps/gateway/app/Http/Gateway/Requests/Apps/DisableAppWebSocketRequest.php`
- Create: `apps/gateway/app/Http/Controllers/Api/AppWebSocketController.php`
- Modify: `apps/gateway/routes/api.php`

### Doctor And Tests

- Modify: `apps/gateway/app/Services/Doctor/DoctorReportRunner.php`
- Modify: node/tool/proxy doctor services touched by the router branch.
- Create: `apps/gateway/tests/Feature/Database/AppWebSocketBindingSchemaTest.php`
- Create: `apps/gateway/tests/Unit/Services/Nodes/WebSocketRoleSettingsTest.php`
- Modify: `apps/gateway/tests/Unit/Services/Nodes/NodeRoleRegistryTest.php`
- Modify: `apps/gateway/tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php`
- Create: `apps/gateway/tests/Unit/Services/WebSockets/WebSocketRuntimeRendererTest.php`
- Create: `apps/gateway/tests/Unit/Services/WebSockets/WebSocketRouteRegistrarTest.php`
- Create: `apps/gateway/tests/Feature/Commands/Nodes/NodeNewWebSocketRoleTest.php`
- Create: `apps/gateway/tests/Feature/Commands/Nodes/NodeRoleAddWebSocketTest.php`
- Create: `apps/gateway/tests/Feature/Commands/Apps/AppWebSocketCommandTest.php`
- Create: `apps/gateway/tests/E2E/WebSocketPrivateRouteTest.php`
- Create: `apps/gateway/tests/E2E/WebSocketIngressRouteTest.php`

## Task 1: Align Product Documentation

**Files:**

- Modify docs listed under Product Docs.

- [ ] **Step 1: Update architecture role language**

Add this contract language to `apps/docs/content/architecture.md`:

```markdown
The `websocket` role is a private workload role for Orbit-managed realtime
infrastructure. A websocket node runs Laravel Reverb, binds only to its
WireGuard address, and receives traffic through router-owned private service
routes. Public WebSocket traffic enters through `ingress`, then flows to
`router`, then to the websocket backend pool. Apps use the stable
`websocket.orbit` endpoint and never target a concrete websocket node.
```

- [ ] **Step 2: Update node concepts**

Add `websocket` to role vocabulary, platform support, settings, and
compatibility. The compatibility row must be:

```markdown
| `websocket` | `app-development`, `database`, `s3` | `gateway`, `vpn`, `router`, `ingress`, `app-production`, `agent` |
```

Add the role setting:

```markdown
| `websocket` | `redis_node_id` | — |
```

- [ ] **Step 3: Update app concepts**

Add this app-owned concept:

```markdown
- **App WebSocket binding:** Gateway-owned app configuration that enables one
  app to use the fleet websocket service. It owns per-app Reverb credentials,
  allowed origins, public WebSocket hosts, and the app's private
  `websocket.orbit` publishing configuration.
```

- [ ] **Step 4: Update proxy/router concepts**

Add this route-placement rule:

```markdown
Public WebSocket hosts are ingress routes that forward to router.
Router owns `websocket.orbit`, websocket backend pools, and private
router-to-websocket TLS verification. Ingress must not route directly to
websocket role nodes.
```

- [ ] **Step 5: Update Reverb tool catalog**

In `apps/docs/content/domains/3_tool/catalog/reverb.md`, state that the installable `reverb`
tool is superseded by the `websocket` role for fleet realtime infrastructure.
The tool catalog entry may remain for compatibility until implementation
removes or migrates it, but app-facing realtime should use the role.

- [ ] **Step 6: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: `issues:0`, `errors:0`, `warnings:0`.

## Task 2: Add App WebSocket Binding State

**Files:**

- Create: `apps/gateway/database/migrations/YYYY_MM_DD_HHMMSS_create_app_websocket_bindings_table.php`
- Create: `apps/gateway/app/Models/AppWebSocketBinding.php`
- Create: `apps/gateway/database/factories/AppWebSocketBindingFactory.php`
- Modify: `apps/gateway/app/Models/App.php`
- Test: `apps/gateway/tests/Feature/Database/AppWebSocketBindingSchemaTest.php`

- [ ] **Step 1: Write the failing schema test**

Create `apps/gateway/tests/Feature/Database/AppWebSocketBindingSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\AppWebSocketBinding;
use Illuminate\Support\Facades\Schema;

it('stores app websocket bindings with encrypted secrets', function (): void {
    expect(Schema::hasTable('app_websocket_bindings'))->toBeTrue();

    $app = App::factory()->create();

    $binding = AppWebSocketBinding::query()->create([
        'app_id' => $app->id,
        'enabled' => true,
        'reverb_app_id' => 'docs',
        'reverb_app_key' => 'public-key',
        'reverb_app_secret' => 'server-secret',
        'allowed_origins' => ['https://example.com'],
        'public_hosts' => ['ws.example.com'],
    ]);

    expect($binding->fresh())
        ->reverb_app_secret->toBe('server-secret')
        ->allowed_origins->toBe(['https://example.com'])
        ->public_hosts->toBe(['ws.example.com']);

    expect(DB::table('app_websocket_bindings')->whereKey($binding->id)->value('reverb_app_secret'))
        ->not->toBe('server-secret');
});
```

- [ ] **Step 2: Run the failing schema test**

Run:

```bash
php artisan test --compact apps/gateway/tests/Feature/Database/AppWebSocketBindingSchemaTest.php
```

Expected: fail because the table and model do not exist.

- [ ] **Step 3: Add the migration**

Create the migration:

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
        Schema::create('app_websocket_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('reverb_app_id')->unique();
            $table->string('reverb_app_key')->unique();
            $table->text('reverb_app_secret');
            $table->json('allowed_origins');
            $table->json('public_hosts');
            $table->timestamps();

            $table->unique('app_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_websocket_bindings');
    }
};
```

- [ ] **Step 4: Add the model**

Create `apps/gateway/app/Models/AppWebSocketBinding.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AppWebSocketBindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $app_id
 * @property bool $enabled
 * @property string $reverb_app_id
 * @property string $reverb_app_key
 * @property string $reverb_app_secret
 * @property list<string> $allowed_origins
 * @property list<string> $public_hosts
 * @property-read App $app
 */
class AppWebSocketBinding extends Model
{
    /** @use HasFactory<AppWebSocketBindingFactory> */
    use HasFactory;

    protected $fillable = [
        'app_id',
        'enabled',
        'reverb_app_id',
        'reverb_app_key',
        'reverb_app_secret',
        'allowed_origins',
        'public_hosts',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'reverb_app_secret' => 'encrypted',
            'allowed_origins' => 'array',
            'public_hosts' => 'array',
        ];
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
```

- [ ] **Step 5: Add the factory and app relation**

Create `apps/gateway/database/factories/AppWebSocketBindingFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use App\Models\AppWebSocketBinding;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppWebSocketBinding>
 */
class AppWebSocketBindingFactory extends Factory
{
    protected $model = AppWebSocketBinding::class;

    public function definition(): array
    {
        $slug = Str::slug($this->faker->unique()->domainWord());

        return [
            'app_id' => App::factory(),
            'enabled' => true,
            'reverb_app_id' => $slug,
            'reverb_app_key' => Str::random(32),
            'reverb_app_secret' => Str::random(48),
            'allowed_origins' => ["https://{$slug}.example.com"],
            'public_hosts' => ["ws.{$slug}.example.com"],
        ];
    }
}
```

Add to `apps/gateway/app/Models/App.php`:

```php
/**
 * @return HasOne<AppWebSocketBinding, $this>
 */
public function webSocketBinding(): HasOne
{
    return $this->hasOne(AppWebSocketBinding::class);
}
```

Also import:

```php
use Illuminate\Database\Eloquent\Relations\HasOne;
```

- [ ] **Step 6: Run the schema test**

Run:

```bash
php artisan test --compact apps/gateway/tests/Feature/Database/AppWebSocketBindingSchemaTest.php
```

Expected: pass.

## Task 3: Add WebSocket Role Model

**Files:**

- Modify: `apps/gateway/app/Enums/Nodes/NodeRoleName.php`
- Create: `apps/gateway/app/Data/Nodes/RoleSettings/WebSocketRoleSettings.php`
- Modify: `apps/gateway/app/Services/Nodes/Roles/NodeRoleRegistry.php`
- Test: `apps/gateway/tests/Unit/Services/Nodes/WebSocketRoleSettingsTest.php`
- Test: `apps/gateway/tests/Unit/Services/Nodes/NodeRoleRegistryTest.php`

- [ ] **Step 1: Write role settings tests**

Create `apps/gateway/tests/Unit/Services/Nodes/WebSocketRoleSettingsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;

it('requires a positive redis node id', function (): void {
    expect(WebSocketRoleSettings::fromArray(['redis_node_id' => 12])->toArray())
        ->toBe(['redis_node_id' => 12]);
});

it('rejects missing redis node id', function (): void {
    expect(fn () => WebSocketRoleSettings::fromArray([]))
        ->toThrow(InvalidArgumentException::class, 'The websocket role requires a valid redis_node_id setting.');
});

it('rejects unknown settings', function (): void {
    expect(fn () => WebSocketRoleSettings::fromArray(['redis_node_id' => 12, 'host' => 'ws.example.com']))
        ->toThrow(InvalidArgumentException::class, 'The websocket role does not accept unknown settings.');
});
```

- [ ] **Step 2: Update registry tests**

In `apps/gateway/tests/Unit/Services/Nodes/NodeRoleRegistryTest.php`, add expectations that:

```php
expect($registry->definition('websocket')->conflictsWith)->toBe([
    'gateway',
    'vpn',
    'router',
    'ingress',
    'app-production',
    'agent',
]);
```

Also assert:

```php
expect($registry->definition('websocket')->settingsClass)
    ->toBe(\App\Data\Nodes\RoleSettings\WebSocketRoleSettings::class);
```

- [ ] **Step 3: Run the failing role tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/Nodes/WebSocketRoleSettingsTest.php apps/gateway/tests/Unit/Services/Nodes/NodeRoleRegistryTest.php
```

Expected: fail because `websocket` is not registered.

- [ ] **Step 4: Add enum and settings class**

Add to `apps/gateway/app/Enums/Nodes/NodeRoleName.php`:

```php
case WebSocket = 'websocket';
```

Create `apps/gateway/app/Data/Nodes/RoleSettings/WebSocketRoleSettings.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

use InvalidArgumentException;

final readonly class WebSocketRoleSettings implements NodeRoleSettings
{
    public function __construct(
        public int $redisNodeId,
    ) {
        if ($redisNodeId < 1) {
            throw new InvalidArgumentException('The websocket role requires a valid redis_node_id setting.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        $unknownKeys = array_diff(array_keys($settings), ['redis_node_id']);

        if ($unknownKeys !== []) {
            throw new InvalidArgumentException('The websocket role does not accept unknown settings.');
        }

        $redisNodeId = $settings['redis_node_id'] ?? null;

        if (! is_int($redisNodeId) || $redisNodeId < 1) {
            throw new InvalidArgumentException('The websocket role requires a valid redis_node_id setting.');
        }

        return new self($redisNodeId);
    }

    #[\Override]
    public function toArray(): array
    {
        return ['redis_node_id' => $this->redisNodeId];
    }
}
```

- [ ] **Step 5: Register the role**

In `NodeRoleRegistry`, register `NodeRoleName::WebSocket->value` with:

```php
new NodeRoleDefinition(
    name: NodeRoleName::WebSocket->value,
    conflictsWith: [
        NodeRoleName::Gateway->value,
        NodeRoleName::Vpn->value,
        NodeRoleName::Router->value,
        NodeRoleName::Ingress->value,
        NodeRoleName::AppProduction->value,
        NodeRoleName::Agent->value,
    ],
    supportedPlatforms: ['ubuntu'],
    settingsClass: WebSocketRoleSettings::class,
)
```

Add `websocket` to every other role's conflict list except `app-development`,
`database`, and `s3`. The S3 role is allowed to co-locate with `websocket` on
dev-services topology nodes.

- [ ] **Step 6: Run the role tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/Nodes/WebSocketRoleSettingsTest.php apps/gateway/tests/Unit/Services/Nodes/NodeRoleRegistryTest.php
```

Expected: pass.

## Task 4: Validate Redis Dependency During Role Assignment

**Files:**

- Modify: `apps/gateway/app/Services/Nodes/Roles/NodeRoleAssignmentService.php`
- Create: `apps/gateway/app/Services/WebSockets/WebSocketRedisResolver.php`
- Test: `apps/gateway/tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php`

- [ ] **Step 1: Add failing assignment tests**

Add tests that prove:

```php
expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'websocket', ['redis_node_id' => $nonDatabaseNode->id]))
    ->toThrow(InvalidArgumentException::class, 'The websocket role requires redis_node_id to reference an active database node with Redis installed.');
```

And a passing test:

```php
$databaseNode = Node::factory()->create(['status' => 'active']);
assignRole($databaseNode, 'database');
NodeTool::factory()->create([
    'node_id' => $databaseNode->id,
    'name' => 'redis',
    'expected_state' => 'installed',
]);

$assignment = app(NodeRoleAssignmentService::class)->add($node, 'websocket', [
    'redis_node_id' => $databaseNode->id,
]);

expect($assignment->settings)->toBe(['redis_node_id' => $databaseNode->id]);
```

- [ ] **Step 2: Run the failing assignment tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php --filter=websocket
```

Expected: fail because Redis dependency validation is missing.

- [ ] **Step 3: Add Redis resolver**

Create `apps/gateway/app/Services/WebSockets/WebSocketRedisResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class WebSocketRedisResolver
{
    public function __construct(
        private NodeRoleAssignments $roles,
    ) {}

    public function usableRedisNode(int $nodeId): ?Node
    {
        $node = Node::query()->find($nodeId);

        if (! $node instanceof Node) {
            return null;
        }

        if (! $this->roles->nodeHasActiveRole($node, 'database')) {
            return null;
        }

        $hasRedis = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'redis')
            ->whereIn('expected_state', ['installed', 'running'])
            ->exists();

        return $hasRedis ? $node : null;
    }
}
```

- [ ] **Step 4: Validate websocket assignment**

In `NodeRoleAssignmentService`, after role settings normalization and before
side effects, add:

```php
if ($role === NodeRoleName::WebSocket->value) {
    $settingsDto = WebSocketRoleSettings::fromArray($settings);

    if (! app(WebSocketRedisResolver::class)->usableRedisNode($settingsDto->redisNodeId) instanceof Node) {
        throw new InvalidArgumentException('The websocket role requires redis_node_id to reference an active database node with Redis installed.');
    }
}
```

Import `Node`, `NodeRoleName`, `WebSocketRoleSettings`, and
`WebSocketRedisResolver` as needed.

- [ ] **Step 5: Run the assignment tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php --filter=websocket
```

Expected: pass.

## Task 5: Render WebSocket Runtime Baseline

**Files:**

- Create: `apps/gateway/app/Services/Nodes/Roles/RoleBaselines/WebSocketRoleBaseline.php`
- Modify: `apps/gateway/app/Services/Nodes/Roles/NodeRoleBaselineConverger.php`
- Create: `apps/gateway/app/Services/WebSockets/WebSocketRuntimeRenderer.php`
- Create: `apps/gateway/app/Services/WebSockets/WebSocketBackendName.php`
- Test: `apps/gateway/tests/Unit/Services/WebSockets/WebSocketRuntimeRendererTest.php`

- [ ] **Step 1: Write renderer tests**

Create tests asserting that rendered environment contains:

```php
expect($env)
    ->toContain('REVERB_SERVER_HOST=10.6.0.44')
    ->toContain('REVERB_SERVER_PORT=8080')
    ->toContain('REVERB_HOST=websocket.orbit')
    ->toContain('REVERB_PORT=443')
    ->toContain('REVERB_SCHEME=https')
    ->toContain('REVERB_SCALING_ENABLED=true')
    ->toContain('REDIS_HOST=redis.orbit');
```

Also assert it does not contain:

```php
expect($env)->not->toContain('REVERB_SERVER_HOST=0.0.0.0');
```

- [ ] **Step 2: Run the failing renderer tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/WebSockets/WebSocketRuntimeRendererTest.php
```

Expected: fail because renderer does not exist.

- [ ] **Step 3: Implement backend naming**

Create `apps/gateway/app/Services/WebSockets/WebSocketBackendName.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Models\Node;

final class WebSocketBackendName
{
    public function forNode(Node $node): string
    {
        return "{$node->name}.websocket.orbit";
    }
}
```

- [ ] **Step 4: Implement runtime renderer**

Create `apps/gateway/app/Services/WebSockets/WebSocketRuntimeRenderer.php` with a method:

```php
public function env(Node $node, WebSocketRoleSettings $settings): string
```

The returned env content must include:

```env
APP_ENV=production
APP_DEBUG=false
BROADCAST_CONNECTION=reverb
REVERB_SERVER_HOST=<node-wireguard-ip>
REVERB_SERVER_PORT=8080
REVERB_HOST=websocket.orbit
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SCALING_ENABLED=true
REDIS_HOST=redis.orbit
REDIS_PORT=6379
```

Read the node WireGuard address from the same node field/helpers used by current
node DNS and gateway API code. If the field is absent, throw:

```php
new RuntimeException('The websocket role requires a WireGuard address before runtime config can be rendered.')
```

- [ ] **Step 5: Add baseline dispatch**

Create `WebSocketRoleBaseline` that:

- rejects gateway nodes;
- requires Ubuntu;
- requires a non-empty host;
- resolves `WebSocketRoleSettings`;
- renders runtime env;
- uploads runtime env and Reverb app files;
- installs backend certs;
- renders/starts the process manager program.

Register it in `NodeRoleBaselineConverger`:

```php
NodeRoleName::WebSocket->value => $this->webSocketRoleBaseline,
```

- [ ] **Step 6: Run renderer tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/WebSockets/WebSocketRuntimeRendererTest.php
```

Expected: pass.

## Task 6: Add Router And Ingress Route Integration

**Files:**

- Create: `apps/gateway/app/Services/WebSockets/WebSocketRouteRegistrar.php`
- Modify: router route rendering services from the router branch.
- Modify: ingress route rendering services from the ingress branch.
- Test: `apps/gateway/tests/Unit/Services/WebSockets/WebSocketRouteRegistrarTest.php`

- [ ] **Step 1: Write route registrar tests**

Create tests that prove:

```php
$registrar->syncServiceRoute();

expect(proxyRouteFor('websocket.orbit'))
    ->node_id->toBe($router->id)
    ->kind->toBe('proxy')
    ->owner_type->toBe('websocket')
    ->config->toMatchArray([
        'protocol' => 'websocket',
        'upstreams' => [
            ['host' => 'ws-1.websocket.orbit', 'port' => 8080, 'scheme' => 'https'],
        ],
    ]);
```

For public host binding:

```php
$registrar->syncPublicHosts($binding);

expect(proxyRouteFor('ws.example.com'))
    ->node_id->toBe($ingress->id)
    ->kind->toBe('proxy')
    ->owner_type->toBe('app-websocket')
    ->config->toMatchArray([
        'target' => ['value' => 'https://websocket.orbit'],
    ]);
```

- [ ] **Step 2: Run failing route tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/WebSockets/WebSocketRouteRegistrarTest.php
```

Expected: fail because registrar does not exist.

- [ ] **Step 3: Implement route registrar**

Create `WebSocketRouteRegistrar` with methods:

```php
public function syncServiceRoute(): ProxyRoute
public function syncPublicHosts(AppWebSocketBinding $binding): void
```

`syncServiceRoute()` resolves the active router role node and active websocket
role backends. It writes one router-owned `websocket.orbit` route with an
`upstreams` list.

`syncPublicHosts()` writes one ingress route per public host. Each route
targets `https://websocket.orbit` and does not include concrete websocket node
backends.

- [ ] **Step 4: Update proxy renderer**

Update the proxy renderer to support:

```php
'protocol' => 'websocket',
'upstreams' => [
    ['scheme' => 'https', 'host' => 'ws-1.websocket.orbit', 'port' => 8080],
]
```

Rendered Caddy must include `reverse_proxy` to the upstreams and must not buffer
or transform WebSocket upgrade traffic. Use the Caddy pattern already used in
the router/ingress branch for long-lived streams.

- [ ] **Step 5: Run route tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/WebSockets/WebSocketRouteRegistrarTest.php
```

Expected: pass.

## Task 7: Add App WebSocket Commands

**Files:**

- Create app websocket commands and API files listed in File Map.
- Modify: `apps/gateway/routes/api.php`
- Test: `apps/gateway/tests/Feature/Commands/Apps/AppWebSocketCommandTest.php`

- [ ] **Step 1: Write command tests**

Create tests for:

```php
Artisan::call('app:websocket enable', [
    'app' => 'docs',
    '--host' => 'ws.example.com',
    '--json' => true,
]);
```

Expected JSON:

```json
{
  "success": {
    "data": {
      "binding": {
        "app": "docs",
        "internal_host": "websocket.orbit",
        "public_hosts": ["ws.example.com"],
        "allowed_origins": ["https://example.com"]
      }
    }
  }
}
```

Also test:

- enabling fails when no active websocket node exists;
- enabling fails when public host is requested without ingress;
- `app:websocket credentials docs --json` includes key and secret;
- two apps get different credentials;
- `app:websocket disable docs --json` disables the binding and removes public
  route intent.

- [ ] **Step 2: Run failing command tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Feature/Commands/Apps/AppWebSocketCommandTest.php
```

Expected: fail because commands do not exist.

- [ ] **Step 3: Implement binding service**

Create `WebSocketBindingService` with:

```php
public function enable(App $app, array $publicHosts): AppWebSocketBinding
public function credentials(App $app): WebSocketCredentials
public function disable(App $app): AppWebSocketBinding
```

Generate:

```php
'reverb_app_id' => $app->name,
'reverb_app_key' => Str::random(32),
'reverb_app_secret' => Str::random(48),
'allowed_origins' => ["https://{$app->domain}"],
```

When an app has no domain, allow private-only binding with an empty public host
list and private `websocket.orbit` config.

- [ ] **Step 4: Implement commands and API**

Add command signatures:

```php
#[Signature('app:websocket enable {app} {--host=*} {--json}')]
#[Signature('app:websocket credentials {app} {--json}')]
#[Signature('app:websocket disable {app} {--json}')]
```

Add matching gateway request/response and API controller methods. Require app
write permission for enable/disable and an explicit credential permission for
credentials, following the existing tool credentials pattern.

- [ ] **Step 5: Run command tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Feature/Commands/Apps/AppWebSocketCommandTest.php
```

Expected: pass.

## Task 8: Add Doctor Coverage

**Files:**

- Modify: `apps/gateway/app/Services/Doctor/DoctorReportRunner.php`
- Modify doctor helpers touched by router/ingress branch.
- Test: existing doctor tests plus websocket-specific tests.

- [ ] **Step 1: Write doctor tests**

Add tests that produce findings for:

- Reverb process missing;
- backend cert missing;
- backend cert name mismatch;
- Redis selected but unreachable;
- router missing `websocket.orbit`;
- ingress missing `ws.example.com` route for enabled binding;
- Reverb bound to `0.0.0.0`.

Expected issue keys:

```text
node.websocket.bind_public_interface
node.websocket.backend_cert_missing
tool.websocket.reverb_unavailable
tool.websocket.redis_unavailable
proxy.websocket.router_route_missing
proxy.websocket.public_route_missing
```

- [ ] **Step 2: Run failing doctor tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Feature/Commands/Operations/DoctorRoleAwareCategoriesTest.php --filter=websocket
```

Expected: fail because doctor does not inspect websocket runtime.

- [ ] **Step 3: Implement doctor checks**

Add websocket node category checks to node doctor and route/runtime checks to
the owning proxy/tool doctor services. Keep ownership split:

- node family owns bind address, cert files, and baseline artifacts;
- proxy family owns router/public route drift;
- tool/runtime checks own Reverb process and Redis reachability.

- [ ] **Step 4: Run doctor tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Feature/Commands/Operations/DoctorRoleAwareCategoriesTest.php --filter=websocket
```

Expected: pass.

## Task 9: Add E2E Coverage

**Files:**

- Create: `apps/gateway/tests/E2E/WebSocketPrivateRouteTest.php`
- Create: `apps/gateway/tests/E2E/WebSocketIngressRouteTest.php`
- Use the existing prepared topology support for the smallest operator
  topologies that cover WebSocket:
  - `operator_gateway_app-dev` for private `websocket.orbit` assertions;
  - `operator_gateway_app-dev_app-prod` for
    `ingress -> router -> websocket` assertions.
  Modify E2E topology internals only if the current contract in
  `apps/docs/content/testing/e2e/**` proves a missing capability.

- [ ] **Step 1: Write E2E test**

Create an E2E test that:

- leases `operator_gateway_app-dev` for private `websocket.orbit` assertions;
- leases `operator_gateway_app-dev_app-prod` for ingress-to-router-to-websocket assertions;
- uses the app-dev node as the dev-services node with `app-development`,
  `database`, `websocket`, and `s3` roles when the S3 role has landed;
- enables websocket for a production app with `ws.<domain>`;
- connects a WebSocket client through ingress;
- publishes a Reverb event through `https://websocket.orbit`;
- asserts the client receives the event.

- [ ] **Step 2: Run focused E2E**

Run:

```bash
composer test:e2e:docker -- apps/gateway/tests/E2E/WebSocketPrivateRouteTest.php apps/gateway/tests/E2E/WebSocketIngressRouteTest.php
```

Expected: pass after WebSocket support is implemented.

## Task 10: Final Verification

**Files:** all modified files.

- [ ] **Step 1: Run focused test suite**

Run:

```bash
php artisan test --compact apps/gateway/tests/Feature/Database/AppWebSocketBindingSchemaTest.php apps/gateway/tests/Unit/Services/Nodes/WebSocketRoleSettingsTest.php apps/gateway/tests/Unit/Services/WebSockets apps/gateway/tests/Feature/Commands/Apps/AppWebSocketCommandTest.php
```

Expected: all pass.

- [ ] **Step 2: Format PHP**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

Expected: no formatting errors.

- [ ] **Step 3: Run quality check**

Run:

```bash
composer quality-check
```

Expected: all checks pass.

- [ ] **Step 4: Run websocket E2E**

Run:

```bash
composer test:e2e:docker -- apps/gateway/tests/E2E/WebSocketPrivateRouteTest.php apps/gateway/tests/E2E/WebSocketIngressRouteTest.php
```

Expected: pass.

## Resolved Decisions And Stop Conditions

- V1 uses the existing app write permission for enable/disable and an explicit
  app credential permission for `app:websocket credentials`, following the
  existing credential-output pattern. Stop and reconcile only if the landed app
  permission registry has no credential-read concept to attach to.
- Private-only development usage defaults to `websocket.orbit`. Browser-facing
  public development hosts must be explicit per application or workspace and
  route through ingress when enabled.
