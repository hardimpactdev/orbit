<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Php;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Workspace;
use App\Services\Php\PhpRuntimeManager;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolScriptDispatcher;
use App\Services\Tools\ToolsProbe;
use App\Services\Workspaces\WorkspaceRoleGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/** @mago-expect lint:file-name */
final readonly class PhpRuntimeInventoryExecutor implements RunsInternalCommands
{
    public function __construct(
        private int $exitCode,
        private string $stdout = '',
        private string $stderr = '',
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'exit_code' => $this->exitCode,
                        'stdout' => $this->stdout,
                        'stderr' => $this->stderr,
                        'duration_ms' => 1,
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        );
    }
}

function bind_php_runtime_inventory_probe(int $exitCode, string $stdout = '', string $stderr = ''): void
{
    $scripts = new ToolScriptDispatcher(new PhpRuntimeInventoryExecutor($exitCode, $stdout, $stderr));

    app()->instance(ToolsProbe::class, new ToolsProbe(scripts: $scripts));
}

function place_php_runtime_manager_app(App $app, Node $node): Instance
{
    return Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/'.$app->name,
            document_root: 'public',
            domain: null,
        ),
    ]);
}

it('does not mask an invalid null workspace PHP snapshot with an inherited version', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => ['versions' => ['8.5'], 'cli_version' => '8.5'],
    ]);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.5']);
    place_php_runtime_manager_app($app, $node);
    Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'php_version' => null]);

    $result = app(PhpRuntimeManager::class)->view(instance: 'docs', workspace: 'feature-docs');

    expect($result->failed())
        ->toBeFalse()
        ->and($result->payload['workspace'])
        ->toBe([
            'name' => 'feature-docs',
            'php_version' => null,
            'inherits' => false,
        ]);
});

it('rejects workspace runtime reads from app-prod callers at the manager boundary', function (): void {
    $caller = Node::factory()->appProd()->create(['name' => 'app-prod-caller']);
    $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.5']);
    $instance = place_php_runtime_manager_app($app, $node);
    Workspace::factory()->create([
        'name' => 'feature-docs',
        'app_id' => $app->id,
        'instance_id' => $instance->id,
    ]);

    $result = app(PhpRuntimeManager::class)->view(
        instance: 'docs.development',
        workspace: 'feature-docs',
        caller: $caller,
    );

    expect($result->failed())
        ->toBeTrue()
        ->and($result->failure?->code)
        ->toBe('workspace.unsupported_for_production')
        ->and($result->failure?->meta)
        ->toMatchArray([
            'node' => 'app-prod-caller',
            'role' => 'app-prod',
        ]);
});

it('rejects workspace runtime reads when the workspace owner is app-prod', function (): void {
    $node = Node::factory()->appProd()->create(['name' => 'app-prod-1']);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.5']);
    $instance = place_php_runtime_manager_app($app, $node);
    Workspace::factory()->create([
        'name' => 'legacy-workspace',
        'app_id' => $app->id,
        'instance_id' => $instance->id,
    ]);

    $result = app(PhpRuntimeManager::class)->view(
        instance: 'docs.development',
        workspace: 'legacy-workspace',
    );

    expect($result->failed())
        ->toBeTrue()
        ->and($result->failure?->code)
        ->toBe('workspace.unsupported_for_production')
        ->and($result->failure?->meta)
        ->toMatchArray([
            'node' => 'app-prod-1',
            'role' => 'app-prod',
        ]);
});

it('writes an instance runtime even when an unrelated workspace placement is unresolved', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
    $app = App::factory()->create([
        'name' => 'docs',
        'php_version' => '8.4',
    ]);
    $instance = place_php_runtime_manager_app($app, $node);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'images' => ['ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm'],
            'versions' => [],
        ],
    ]);
    $unresolvedInstance = Instance::factory()->for($app)->create([
        'name' => 'legacy',
        'driver_config' => new OrbitInstanceDriverConfigData,
    ]);
    $workspace = Workspace::factory()->create([
        'name' => 'legacy-workspace',
        'app_id' => $app->id,
        'instance_id' => $unresolvedInstance->id,
        'php_version' => '8.4',
    ]);

    expect(app(WorkspaceRoleGuard::class)->allowsWorkspaceTarget($workspace))->toBeFalse();

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', instance: 'docs.development');

    // The write lands on one instance row, so a sibling instance's unresolved
    // workspace has nothing to gate.
    expect($result->failed())
        ->toBeFalse()
        ->and($instance->refresh()->php_version)
        ->toBe('8.5')
        ->and($app->refresh()->php_version)
        ->toBe('8.4')
        ->and($workspace->refresh()->php_version)
        ->toBe('8.4');
});

it('frankenphp selects app runtime from approved image facts', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'images' => ['ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm'],
            'versions' => [],
            'cli_version' => null,
        ],
    ]);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.4']);
    $instance = place_php_runtime_manager_app($app, $node);

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', instance: 'docs');

    expect($result->failed())
        ->toBeFalse()
        ->and($instance->refresh()->php_version)
        ->toBe('8.5')
        ->and($result->payload['result'])
        ->toMatchArray([
            'target' => 'instance',
            'app' => 'docs',
            'version' => '8.5',
            'image' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
        ]);
});

it('refreshes stale PHP image inventory on a macOS Docker-backed node and stays idempotent', function (): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'nckrtl',
            'platform' => 'macos_14',
        ]);
    $app = App::factory()->create(['name' => 'nckrtl', 'php_version' => '8.4']);
    place_php_runtime_manager_app($app, $node)->forceFill(['name' => 'nmbp'])->save();
    bind_php_runtime_inventory_probe(0, stdout: "ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm\n");

    $first = app(PhpRuntimeManager::class)->use(version: '8.5', instance: 'nckrtl.nmbp');
    $second = app(PhpRuntimeManager::class)->use(version: '8.5', instance: 'nckrtl.nmbp');

    expect($first->failed())
        ->toBeFalse()
        ->and($second->failed())
        ->toBeFalse()
        ->and($second->payload['result']['changed'])
        ->toBeFalse()
        ->and(NodeTool::query()->whereBelongsTo($node)->where('name', 'php')->firstOrFail()->config)
        ->toMatchArray([
            'images' => ['ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm'],
            'versions' => ['8.5'],
            'image_inventory_status' => 'confirmed',
        ]);
});

it('does not register PHP inventory on an unsupported node', function (): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'nckrtl',
            'platform' => 'windows_11',
        ]);
    $app = App::factory()->create(['name' => 'nckrtl', 'php_version' => '8.4']);
    place_php_runtime_manager_app($app, $node)->forceFill(['name' => 'nmbp'])->save();

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', instance: 'nckrtl.nmbp');

    expect($result->failed())
        ->toBeTrue()
        ->and($result->failure?->code)
        ->toBe('php.image_inventory_unavailable')
        ->and(NodeTool::query()->whereBelongsTo($node)->where('name', 'php')->exists())
        ->toBeFalse();
});

it('reports a confirmed missing approved image after a successful empty inventory probe', function (): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'nckrtl',
            'platform' => 'macos_14',
        ]);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [],
    ]);
    $app = App::factory()->create(['name' => 'nckrtl', 'php_version' => '8.4']);
    place_php_runtime_manager_app($app, $node)->forceFill(['name' => 'nmbp'])->save();
    bind_php_runtime_inventory_probe(0);

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', instance: 'nckrtl.nmbp');

    expect($result->failed())
        ->toBeTrue()
        ->and($result->failure?->meta)
        ->toMatchArray([
            'reason' => 'not_installed',
            'inventory_status' => 'confirmed',
            'image' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
        ]);
});

it('does not misreport an unavailable image inventory as a confirmed missing image', function (): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'nckrtl',
            'platform' => 'macos_14',
        ]);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'images' => ['ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm'],
            'versions' => ['8.5'],
            'image_inventory_status' => 'unavailable',
            'image_inventory_error' => 'The previous probe failed.',
        ],
    ]);
    $app = App::factory()->create(['name' => 'nckrtl', 'php_version' => '8.4']);
    place_php_runtime_manager_app($app, $node)->forceFill(['name' => 'nmbp'])->save();
    bind_php_runtime_inventory_probe(2, stderr: 'Docker-compatible provider is unreachable.');

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', instance: 'nckrtl.nmbp');

    expect($result->failed())
        ->toBeTrue()
        ->and($result->failure?->code)
        ->toBe('php.image_inventory_unavailable')
        ->and($result->failure?->meta)
        ->toMatchArray([
            'reason' => 'inventory_unavailable',
            'node' => 'nckrtl',
        ]);
});

it('frankenphp exposes available image facts in runtime views', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'images' => ['ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm'],
            'versions' => [],
            'cli_version' => '8.5',
        ],
    ]);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.5']);
    place_php_runtime_manager_app($app, $node);

    $result = app(PhpRuntimeManager::class)->view(instance: 'docs');

    expect($result->failed())
        ->toBeFalse()
        ->and($result->payload)
        ->toHaveKey('available_images')
        ->and($result->payload['available_images'])
        ->toBe(['8.5'])
        ->and($result->payload)
        ->not->toHaveKey('installed');
});

it('frankenphp rejects app writes when --node does not own the app', function (): void {
    $appNode = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $appNode->id,
        'name' => 'php',
        'config' => [
            'versions' => ['8.5'],
            'cli_version' => '8.5',
        ],
    ]);

    $imageNode = Node::factory()->appDev()->create(['name' => 'image-node']);
    NodeTool::factory()->create([
        'node_id' => $imageNode->id,
        'name' => 'php',
        'config' => [
            'images' => ['ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm'],
            'versions' => [],
            'cli_version' => null,
        ],
    ]);

    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.4']);
    place_php_runtime_manager_app($app, $appNode);

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', instance: 'docs', node: 'image-node');

    expect($result->failed())
        ->toBeTrue()
        ->and($app->refresh()->php_version)
        ->toBe('8.4')
        ->and($result->failure?->code)
        ->toBe('validation_failed')
        ->and($result->failure?->meta)
        ->toMatchArray([
            'field' => 'node',
            'reason' => 'target_mismatch',
            'node' => 'image-node',
            'app' => 'docs',
            'owning_node' => 'app-1',
        ]);
});

it('selects node CLI PHP through host php-cli facts without FrankenPHP image data', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'beast']);
    $phpTool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'images' => [],
            'versions' => [],
            'cli_version' => '8.4',
        ],
    ]);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
        'expected_state' => 'installed',
    ]);

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', node: 'beast', cli: true);

    expect($result->failed())
        ->toBeFalse()
        ->and($phpTool->refresh()->config['cli_version'])
        ->toBe('8.5')
        ->and($result->payload['result'])
        ->toMatchArray([
            'target' => 'node_cli',
            'node' => 'beast',
            'previous' => '8.4',
            'version' => '8.5',
            'changed' => true,
        ])
        ->and($result->payload['result'])
        ->not->toHaveKey('image');
});

it('reports node CLI selection as idempotent when the default is already PHP 8.5', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'beast']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => ['cli_version' => '8.5'],
    ]);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
        'expected_state' => 'installed',
    ]);

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', node: 'beast', cli: true);

    expect($result->failed())->toBeFalse()->and($result->payload['result']['changed'])->toBeFalse();
});

it('rejects node CLI selection when the host php-cli toolchain is missing', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'beast']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => ['cli_version' => '8.4'],
    ]);

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', node: 'beast', cli: true);

    expect($result->failed())
        ->toBeTrue()
        ->and($result->failure?->meta)
        ->toMatchArray([
            'field' => 'version',
            'reason' => 'php_cli_missing',
            'node' => 'beast',
        ]);
});

it('rejects CLI PHP selection for versions other than 8.5', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'images' => [
                'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
                'ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.4-bookworm',
            ],
            'versions' => ['8.5', '8.4'],
            'cli_version' => '8.5',
        ],
    ]);

    $result = app(PhpRuntimeManager::class)->use(version: '8.4', node: 'app-1', cli: true);

    expect($result->failed())
        ->toBeTrue()
        ->and($tool->refresh()->config['cli_version'])
        ->toBe('8.5')
        ->and($result->failure?->meta)
        ->toMatchArray([
            'field' => 'version',
            'reason' => 'unsupported_cli_version',
            'supported' => ['8.5'],
        ]);
});

it('frankenphp rejects workspace writes when --node does not own the parent app', function (): void {
    $appNode = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $appNode->id,
        'name' => 'php',
        'config' => [
            'versions' => ['8.5'],
            'cli_version' => '8.5',
        ],
    ]);

    $imageNode = Node::factory()->appDev()->create(['name' => 'image-node']);
    NodeTool::factory()->create([
        'node_id' => $imageNode->id,
        'name' => 'php',
        'config' => [
            'images' => ['ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm'],
            'versions' => [],
            'cli_version' => null,
        ],
    ]);

    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.4']);
    place_php_runtime_manager_app($app, $appNode);
    $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'php_version' => '8.4']);

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', workspace: 'feature-docs', node: 'image-node');

    expect($result->failed())
        ->toBeTrue()
        ->and($workspace->refresh()->php_version)
        ->toBe('8.4')
        ->and($result->failure?->code)
        ->toBe('validation_failed')
        ->and($result->failure?->meta)
        ->toMatchArray([
            'field' => 'node',
            'reason' => 'target_mismatch',
            'node' => 'image-node',
            'app' => 'docs',
            'owning_node' => 'app-1',
        ]);
});

it('frankenphp rejects host PHP and FPM fallback facts even when legacy version facts exist', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'images' => [
                'php:8.5-fpm-bookworm',
                'php:8.5-cli-bookworm',
                'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-alpine',
            ],
            'versions' => ['8.5'],
            'cli_version' => '8.5',
        ],
    ]);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.4']);
    place_php_runtime_manager_app($app, $node);

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', instance: 'docs');

    expect($result->failed())
        ->toBeTrue()
        ->and($app->refresh()->php_version)
        ->toBe('8.4')
        ->and($result->failure?->code)
        ->toBe('validation_failed')
        ->and($result->failure?->meta)
        ->toMatchArray([
            'field' => 'version',
            'reason' => 'not_installed',
            'node' => 'app-1',
            'version' => '8.5',
            'image' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
            'rejected_images' => [
                'php:8.5-fpm-bookworm',
                'php:8.5-cli-bookworm',
                'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-alpine',
            ],
        ]);
});

it('frankenphp rejects legacy versions-only PHP facts without approved image evidence', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'versions' => ['8.5'],
            'cli_version' => '8.5',
        ],
    ]);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.4']);
    place_php_runtime_manager_app($app, $node);

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', instance: 'docs');

    expect($result->failed())
        ->toBeTrue()
        ->and($app->refresh()->php_version)
        ->toBe('8.4')
        ->and($result->failure?->meta)
        ->toMatchArray([
            'field' => 'version',
            'reason' => 'not_installed',
            'version' => '8.5',
            'image' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
        ]);
});

it('requires an explicit version for workspace PHP writes', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'versions' => ['8.5'],
            'cli_version' => '8.5',
        ],
    ]);
    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.5']);
    place_php_runtime_manager_app($app, $node);
    $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'php_version' => '8.4']);

    $result = app(PhpRuntimeManager::class)->use(version: null, instance: 'docs', workspace: 'feature-docs');

    expect($result->failed())
        ->toBeTrue()
        ->and($workspace->refresh()->php_version)
        ->toBe('8.4')
        ->and($result->failure?->code)
        ->toBe('validation_failed')
        ->and($result->failure?->meta)
        ->toBe(['field' => 'version', 'reason' => 'missing_required_input']);
});
