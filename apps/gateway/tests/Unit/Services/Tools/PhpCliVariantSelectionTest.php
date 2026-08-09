<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\NodeToolBaselineConfigRenderer;
use App\Services\Nodes\Roles\RoleBaselines\AppDevelopmentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppProductionRoleBaseline;
use App\Services\Tools\ToolInstallConfigValidator;
use App\Services\Tools\ToolInstaller;
use App\Services\Tools\ToolRegistryFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Php\PhpCliVariant;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function phpCliVariantNode(string $role): Node
{
    $node = Node::factory()->create([
        'status' => NodeStatus::Active,
        'platform' => 'ubuntu',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    return $node->fresh();
}

it('renders coverage config for app-dev and standard for app-prod', function (): void {
    $renderer = new NodeToolBaselineConfigRenderer;
    $dev = phpCliVariantNode(NodeRoleName::AppDevelopment->value);
    $prod = phpCliVariantNode(NodeRoleName::AppProduction->value);

    expect($renderer->render('php-cli', $dev))
        ->toBe(['variant' => PhpCliVariant::Coverage->value])
        ->and($renderer->render('php-cli', $prod))
        ->toBe(['variant' => PhpCliVariant::Standard->value]);
});

it('app-dev baseline persists coverage and app-prod persists standard', function (): void {
    $dev = phpCliVariantNode(NodeRoleName::AppDevelopment->value);
    $prod = phpCliVariantNode(NodeRoleName::AppProduction->value);
    $devAssignment = $dev->roleAssignments()->firstOrFail();
    $prodAssignment = $prod->roleAssignments()->firstOrFail();

    new AppDevelopmentRoleBaseline()->converge($dev, $devAssignment);
    new AppProductionRoleBaseline()->converge($prod, $prodAssignment);

    $devTool = NodeTool::query()->where('node_id', $dev->id)->where('name', 'php-cli')->first();
    $prodTool = NodeTool::query()->where('node_id', $prod->id)->where('name', 'php-cli')->first();

    expect($devTool?->config['variant'] ?? null)
        ->toBe('coverage')
        ->and($prodTool?->config['variant'] ?? null)
        ->toBe('standard');
});

it('rejects invalid explicit php-cli variants', function (): void {
    $failure = new ToolInstallConfigValidator()->validate('php-cli', ['variant' => 'debug']);

    expect($failure)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($failure->message)
        ->toContain('Invalid php-cli variant');
});

it('manual install without config preserves stored role-derived variant', function (): void {
    $node = phpCliVariantNode(NodeRoleName::AppProduction->value);

    NodeTool::query()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
        'expected_state' => 'installed',
        'config' => ['variant' => 'standard'],
    ]);

    $installer = app(ToolInstaller::class);
    $method = new ReflectionMethod($installer, 'resolvePhpCliConfig');
    $method->setAccessible(true);

    $resolved = $method->invoke($installer, $node, []);

    expect($resolved['variant'])->toBe('standard');
});

it('manual install without stored config resolves app-dev to coverage', function (): void {
    $node = phpCliVariantNode(NodeRoleName::AppDevelopment->value);
    $installer = app(ToolInstaller::class);
    $method = new ReflectionMethod($installer, 'resolvePhpCliConfig');
    $method->setAccessible(true);

    $resolved = $method->invoke($installer, $node, []);

    expect($resolved['variant'])->toBe('coverage');
});

it('rejects explicit coverage on app-prod and explicit standard on app-dev', function (): void {
    $prod = phpCliVariantNode(NodeRoleName::AppProduction->value);
    $dev = phpCliVariantNode(NodeRoleName::AppDevelopment->value);
    $installer = app(ToolInstaller::class);
    $method = new ReflectionMethod($installer, 'resolvePhpCliConfig');
    $method->setAccessible(true);

    $prodConflict = $method->invoke($installer, $prod, ['variant' => 'coverage']);
    $devConflict = $method->invoke($installer, $dev, ['variant' => 'standard']);

    expect($prodConflict)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($prodConflict->message)
        ->toContain("not allowed on role 'app-prod'")
        ->and($devConflict)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($devConflict->message)
        ->toContain("not allowed on role 'app-dev'");
});

it('role reconciliation overwrites stale stored coverage on app-prod to standard', function (): void {
    $node = phpCliVariantNode(NodeRoleName::AppProduction->value);

    NodeTool::query()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
        'expected_state' => 'installed',
        'config' => ['variant' => 'coverage'],
    ]);

    $installer = app(ToolInstaller::class);
    $method = new ReflectionMethod($installer, 'resolvePhpCliConfig');
    $method->setAccessible(true);

    // Empty install config must not preserve stale coverage on app-prod.
    $resolved = $method->invoke($installer, $node, []);
    expect($resolved['variant'])->toBe('standard');

    // Baseline reconverge also rewrites the stored row.
    $assignment = $node->roleAssignments()->firstOrFail();
    new AppProductionRoleBaseline()->converge($node, $assignment);
    $tool = NodeTool::query()->where('node_id', $node->id)->where('name', 'php-cli')->first();
    expect($tool?->config['variant'] ?? null)->toBe('standard');
});

it('role reconciliation overwrites stale stored standard on app-dev to coverage', function (): void {
    $node = phpCliVariantNode(NodeRoleName::AppDevelopment->value);

    NodeTool::query()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
        'expected_state' => 'installed',
        'config' => ['variant' => 'standard'],
    ]);

    $installer = app(ToolInstaller::class);
    $method = new ReflectionMethod($installer, 'resolvePhpCliConfig');
    $method->setAccessible(true);

    $resolved = $method->invoke($installer, $node, []);
    expect($resolved['variant'])->toBe('coverage');

    $assignment = $node->roleAssignments()->firstOrFail();
    new AppDevelopmentRoleBaseline()->converge($node, $assignment);
    $tool = NodeTool::query()->where('node_id', $node->id)->where('name', 'php-cli')->first();
    expect($tool?->config['variant'] ?? null)->toBe('coverage');
});

it('manual install without a role may keep an explicit stored or requested variant', function (): void {
    $node = Node::factory()->create([
        'status' => NodeStatus::Active,
        'platform' => 'ubuntu',
    ]);

    $installer = app(ToolInstaller::class);
    $method = new ReflectionMethod($installer, 'resolvePhpCliConfig');
    $method->setAccessible(true);

    $explicit = $method->invoke($installer, $node, ['variant' => 'standard']);
    expect($explicit['variant'])->toBe('standard');

    NodeTool::query()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
        'expected_state' => 'installed',
        'config' => ['variant' => 'standard'],
    ]);

    $stored = $method->invoke($installer, $node, []);
    expect($stored['variant'])->toBe('standard');
});
