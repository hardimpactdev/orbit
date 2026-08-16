<?php

declare(strict_types=1);

use App\Concerns\PromptsForRegistryEntities;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @return object{apps: callable, workspaces: callable}
 */
function registryPrompts(): object
{
    return new class {
        use PromptsForRegistryEntities;

        /**
         * @return list<array<string, mixed>>
         */
        public function apps(?string $node = null, ?string $environment = null): array
        {
            $payloads = $this->visibleAppPromptPayloads($node, $environment);

            return is_array($payloads) ? $payloads : [];
        }

        /**
         * @return list<array<string, mixed>>
         */
        public function workspaces(?string $app = null, ?string $node = null): array
        {
            $payloads = $this->visibleWorkspacePromptPayloads($app, $node);

            return is_array($payloads) ? $payloads : [];
        }
    };
}

/**
 * @param  list<array<string, mixed>>  $payloads
 * @return list<string>
 */
function promptNames(array $payloads): array
{
    return array_values(array_map(static fn (array $payload): string => (string) $payload['name'], $payloads));
}

it('filters apps by node name across every instance of a multi-instance app', function (): void {
    $devNode = createTestAppHostNode(['name' => 'dev-node', 'tld' => 'devnode']);
    $prodNode = createTestAppHostNode(attributes: ['name' => 'prod-node', 'tld' => 'prodnode'], role: 'app-prod');
    $otherNode = createTestAppHostNode(['name' => 'other-node', 'tld' => 'othernode']);

    // Multi-instance app spanning two nodes.
    $alpha = App::factory()->create(['name' => 'alpha']);
    Instance::factory()->for($alpha, 'app')->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $devNode->id,
            node: $devNode->name,
            path: '/home/orbit/apps/alpha',
            document_root: 'public',
        ),
    ]);
    Instance::factory()->for($alpha, 'app')->create([
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $prodNode->id,
            node: $prodNode->name,
            path: '/srv/apps/alpha',
            document_root: 'public',
            domain: 'alpha.example.com',
        ),
    ]);

    // Single-instance app on a different node.
    $beta = App::factory()->create(['name' => 'beta']);
    Instance::factory()->for($beta, 'app')->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $otherNode->id,
            node: $otherNode->name,
            path: '/home/orbit/apps/beta',
            document_root: 'public',
        ),
    ]);

    $prompts = registryPrompts();

    // No filter → both apps.
    expect(promptNames($prompts->apps()))->toBe(['alpha', 'beta']);
    // Filter by the dev node → only alpha (its development instance is there).
    expect(promptNames($prompts->apps(node: 'dev-node')))->toBe(['alpha']);
    // Filter by the prod node → still alpha, matched on its SECONDARY instance.
    expect(promptNames($prompts->apps(node: 'prod-node')))->toBe(['alpha']);
    // Filter by the other node → only beta.
    expect(promptNames($prompts->apps(node: 'other-node')))->toBe(['beta']);
});

it('filters apps by environment across every instance of a multi-instance app', function (): void {
    $devNode = createTestAppHostNode(['name' => 'dev-node', 'tld' => 'devnode']);
    $prodNode = createTestAppHostNode(attributes: ['name' => 'prod-node', 'tld' => 'prodnode'], role: 'app-prod');

    $alpha = App::factory()->create(['name' => 'alpha']);
    Instance::factory()->for($alpha, 'app')->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $devNode->id,
            node: $devNode->name,
            path: '/home/orbit/apps/alpha',
            document_root: 'public',
        ),
    ]);
    Instance::factory()->for($alpha, 'app')->create([
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $prodNode->id,
            node: $prodNode->name,
            path: '/srv/apps/alpha',
            document_root: 'public',
            domain: 'alpha.example.com',
        ),
    ]);

    // Development-only app.
    $beta = App::factory()->create(['name' => 'beta']);
    Instance::factory()->for($beta, 'app')->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $devNode->id,
            node: $devNode->name,
            path: '/home/orbit/apps/beta',
            document_root: 'public',
        ),
    ]);

    $prompts = registryPrompts();

    // Production filter matches alpha via its production instance, not beta.
    expect(promptNames($prompts->apps(environment: 'production')))->toBe(['alpha']);
    // Development filter matches both (each has a development instance).
    expect(promptNames($prompts->apps(environment: 'development')))->toBe(['alpha', 'beta']);
});

it('filters workspaces by their instance node, not a stale app node', function (): void {
    $realNode = createTestAppHostNode(['name' => 'real-node', 'tld' => 'realnode']);
    createTestAppHostNode(['name' => 'stale-node', 'tld' => 'stalenode']);

    // The app is logical-only; its workspace instance is placed on the real node.
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app, 'app')->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $realNode->id,
            node: $realNode->name,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
        ),
    ]);
    Workspace::factory()->for($app, 'app')->for($instance, 'instance')->create(['name' => 'feature-a']);

    $prompts = registryPrompts();

    // The workspace is visible under its instance's real node, not the stale app node.
    expect(promptNames($prompts->workspaces(node: 'real-node')))->toBe(['feature-a']);
    expect($prompts->workspaces(node: 'stale-node'))->toBe([]);
});
