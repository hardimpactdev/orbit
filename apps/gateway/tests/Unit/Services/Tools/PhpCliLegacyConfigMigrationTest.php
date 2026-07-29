<?php

declare(strict_types=1);

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\RoleBaselines\AppDevelopmentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppProductionRoleBaseline;
use App\Services\Tools\PhpCliVariantResolver;
use App\Services\Tools\ToolsFixer;
use App\Services\Tools\ToolsProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Php\PhpCliVariant;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{0: Node, 1: NodeTool}
 */
function phpCliLegacyNullConfigTool(string $role): array
{
    $token = bin2hex(random_bytes(3));
    $suffix = random_int(20, 250);

    $node = Node::factory()->create([
        'status' => NodeStatus::Active,
        'platform' => 'ubuntu',
        'host' => "10.0.0.{$suffix}",
        'tld' => "phpcli-legacy-{$token}",
        'wireguard_address' => "10.10.0.{$suffix}",
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    $tool = NodeTool::query()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
        'expected_state' => 'installed',
        'config' => null,
    ]);

    return [$node->fresh(), $tool->fresh()];
}

it('derives standard for legacy null-config app-prod rows without preferring coverage', function (): void {
    [$node, $tool] = phpCliLegacyNullConfigTool(NodeRoleName::AppProduction->value);

    expect($tool->config)->toBeNull();

    $variant = app(PhpCliVariantResolver::class)->forTool($tool);

    expect($variant)
        ->toBe(PhpCliVariant::Standard)
        ->and(app(PhpCliVariantResolver::class)->configLacksVariant($tool->config))
        ->toBeTrue();

    // Doctor restore must reinstall standard, never coverage, for app-prod.
    $fixer = new ToolsFixer;
    $configMethod = new ReflectionMethod($fixer, 'configForToolScript');
    $configMethod->setAccessible(true);
    $scriptConfig = $configMethod->invoke($fixer, $tool);

    expect($scriptConfig['variant'] ?? null)->toBe('standard');

    $probe = new ToolsProbe;
    $variantMethod = new ReflectionMethod($probe, 'phpCliVariantForTool');
    $variantMethod->setAccessible(true);

    expect($variantMethod->invoke($probe, $tool))->toBe(PhpCliVariant::Standard);
});

it('derives coverage for legacy null-config app-dev rows', function (): void {
    [$node, $tool] = phpCliLegacyNullConfigTool(NodeRoleName::AppDevelopment->value);

    expect($tool->config)
        ->toBeNull()
        ->and(app(PhpCliVariantResolver::class)->forTool($tool))
        ->toBe(PhpCliVariant::Coverage);

    $probe = new ToolsProbe;
    $variantMethod = new ReflectionMethod($probe, 'phpCliVariantForTool');
    $variantMethod->setAccessible(true);

    expect($variantMethod->invoke($probe, $tool))->toBe(PhpCliVariant::Coverage);
});

it('backfills persisted variant on role-owned null-config php-cli rows', function (): void {
    [, $prodTool] = phpCliLegacyNullConfigTool(NodeRoleName::AppProduction->value);
    [, $devTool] = phpCliLegacyNullConfigTool(NodeRoleName::AppDevelopment->value);

    expect($prodTool->config)->toBeNull()->and($devTool->config)->toBeNull();

    // Re-run the backfill migration body against the live test database.
    $migration = require
        database_path('migrations/2026_07_29_185506_backfill_php_cli_variant_on_role_owned_node_tools.php');
    $migration->up();

    $prodTool->refresh();
    $devTool->refresh();

    expect($prodTool->config)->toBe(['variant' => 'standard'])->and($devTool->config)->toBe(['variant' => 'coverage']);
});

it('overwrites stale coverage on app-prod and stale standard on app-dev during backfill', function (): void {
    [$prodNode] = phpCliLegacyNullConfigTool(NodeRoleName::AppProduction->value);
    [$devNode] = phpCliLegacyNullConfigTool(NodeRoleName::AppDevelopment->value);

    // Replace the null-config rows with intentionally wrong variants.
    NodeTool::query()
        ->where('node_id', $prodNode->id)
        ->where('name', 'php-cli')
        ->update([
            'config' => json_encode(['variant' => 'coverage', 'note' => 'stale-prod'], JSON_THROW_ON_ERROR),
        ]);
    NodeTool::query()
        ->where('node_id', $devNode->id)
        ->where('name', 'php-cli')
        ->update([
            'config' => json_encode(['variant' => 'standard', 'note' => 'stale-dev'], JSON_THROW_ON_ERROR),
        ]);

    $migration = require
        database_path('migrations/2026_07_29_185506_backfill_php_cli_variant_on_role_owned_node_tools.php');
    $migration->up();

    $prodTool = NodeTool::query()->where('node_id', $prodNode->id)->where('name', 'php-cli')->firstOrFail();
    $devTool = NodeTool::query()->where('node_id', $devNode->id)->where('name', 'php-cli')->firstOrFail();

    expect($prodTool->config)
        ->toBe([
            'variant' => 'standard',
            'note' => 'stale-prod',
        ])
        ->and($devTool->config)
        ->toBe([
            'variant' => 'coverage',
            'note' => 'stale-dev',
        ]);
});

it('skips backfill only when stored variant already matches role ownership', function (): void {
    [$prodNode] = phpCliLegacyNullConfigTool(NodeRoleName::AppProduction->value);
    [$devNode] = phpCliLegacyNullConfigTool(NodeRoleName::AppDevelopment->value);

    NodeTool::query()
        ->where('node_id', $prodNode->id)
        ->where('name', 'php-cli')
        ->update([
            'config' => json_encode(['variant' => 'standard', 'kept' => true], JSON_THROW_ON_ERROR),
            'updated_at' => '2020-01-01 00:00:00',
        ]);
    NodeTool::query()
        ->where('node_id', $devNode->id)
        ->where('name', 'php-cli')
        ->update([
            'config' => json_encode(['variant' => 'coverage', 'kept' => true], JSON_THROW_ON_ERROR),
            'updated_at' => '2020-01-01 00:00:00',
        ]);

    $migration = require
        database_path('migrations/2026_07_29_185506_backfill_php_cli_variant_on_role_owned_node_tools.php');
    $migration->up();

    $prodTool = NodeTool::query()->where('node_id', $prodNode->id)->where('name', 'php-cli')->firstOrFail();
    $devTool = NodeTool::query()->where('node_id', $devNode->id)->where('name', 'php-cli')->firstOrFail();

    expect($prodTool->config)
        ->toBe(['variant' => 'standard', 'kept' => true])
        ->and($devTool->config)
        ->toBe(['variant' => 'coverage', 'kept' => true])
        ->and((string) $prodTool->updated_at)
        ->toStartWith('2020-01-01');
});

it('role baseline convergence rewrites null config to the role-owned variant', function (): void {
    [$prodNode, $prodTool] = phpCliLegacyNullConfigTool(NodeRoleName::AppProduction->value);
    [$devNode, $devTool] = phpCliLegacyNullConfigTool(NodeRoleName::AppDevelopment->value);

    expect($prodTool->config)->toBeNull()->and($devTool->config)->toBeNull();

    new AppProductionRoleBaseline()->converge(
        $prodNode,
        $prodNode->roleAssignments()->firstOrFail(),
    );
    new AppDevelopmentRoleBaseline()->converge(
        $devNode,
        $devNode->roleAssignments()->firstOrFail(),
    );

    expect($prodTool->fresh()->config)
        ->toBe(['variant' => 'standard'])
        ->and($devTool->fresh()->config)
        ->toBe(['variant' => 'coverage']);
});

it('does not treat null-config app-prod as coverage in doctor restore scripts', function (): void {
    [, $tool] = phpCliLegacyNullConfigTool(NodeRoleName::AppProduction->value);

    $fixer = new ToolsFixer;
    $method = new ReflectionMethod($fixer, 'repairCommand');
    $method->setAccessible(true);

    $entry = new DriftEntry(
        family: 'tool',
        key: 'tool.capability_missing',
        kind: DriftKind::Missing,
        summary: 'missing',
    );

    $command = $method->invoke($fixer, $tool, $entry);

    expect($command)
        ->toBeString()
        // Compatibility contract still uses non-variant artifact names; ensure we do
        // not request coverage-named matrix artifacts for production restore.
        ->not
        ->toContain('php-8.5.8-cli-coverage-')
        ->toContain('/opt/orbit/php');
});
