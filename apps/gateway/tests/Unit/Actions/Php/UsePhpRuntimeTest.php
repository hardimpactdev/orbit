<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Php;

use App\Actions\Php\UsePhpRuntime;
use App\Contracts\PhpRuntimeArtifactConverger;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function place_use_php_runtime_app(App $app, Node $node): Instance
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

/** @mago-expect lint:file-name */
final class RecordingPhpRuntimeArtifactConverger implements PhpRuntimeArtifactConverger
{
    /** @var list<string> */
    public array $targets = [];

    public function forApp(App $app): array
    {
        $this->targets[] = "app:{$app->name}";

        return [[
            'code' => 'process.runtime_unit_missing',
            'family' => 'process',
            'message' => 'Runtime convergence is pending.',
            'next_command' => 'doctor --family=process --restore',
        ]];
    }

    public function forWorkspace(Workspace $workspace, Node $node): array
    {
        $this->targets[] = "workspace:{$workspace->name}@{$node->name}";

        return [];
    }
}

it('converges an app runtime after writing PHP selection intent', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'images' => ['ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm'],
        ],
    ]);
    $app = App::factory()->create([
        'name' => 'docs',
        'php_version' => '8.4',
    ]);
    $instance = place_use_php_runtime_app($app, $node);
    $converger = new RecordingPhpRuntimeArtifactConverger;
    app()->instance(PhpRuntimeArtifactConverger::class, $converger);

    $result = app(UsePhpRuntime::class)->handle(version: '8.5', instance: 'docs');

    expect($result->failed())
        ->toBeFalse()
        ->and($app->refresh()->php_version)
        ->toBe('8.4')
        ->and($instance->refresh()->php_version)
        ->toBe('8.5')
        ->and($converger->targets)
        ->toBe(['app:docs'])
        ->and($result->meta['warnings'])
        ->toHaveCount(1)
        ->and($result->meta['warnings'][0]['code'])
        ->toBe('process.runtime_unit_missing');
});

it('converges a workspace runtime on its owning node after selection', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'workspace-node']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'images' => ['ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm'],
        ],
    ]);
    $app = App::factory()->create([
        'name' => 'docs',
        'php_version' => '8.5',
    ]);
    place_use_php_runtime_app($app, $node);
    $workspace = Workspace::factory()->create([
        'name' => 'feature-docs',
        'app_id' => $app->id,
        'php_version' => '8.4',
    ]);
    $converger = new RecordingPhpRuntimeArtifactConverger;
    app()->instance(PhpRuntimeArtifactConverger::class, $converger);

    $result = app(UsePhpRuntime::class)->handle(version: '8.5', workspace: 'feature-docs');

    expect($result->failed())
        ->toBeFalse()
        ->and($workspace->refresh()->php_version)
        ->toBe('8.5')
        ->and($converger->targets)
        ->toBe(['workspace:feature-docs@workspace-node']);
});

it('does not dispatch node CLI intent through runtime artifact convergence', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
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
    $converger = new RecordingPhpRuntimeArtifactConverger;
    app()->instance(PhpRuntimeArtifactConverger::class, $converger);

    $result = app(UsePhpRuntime::class)->handle(version: '8.5', node: 'app-1', cli: true);

    expect($result->failed())
        ->toBeFalse()
        ->and($converger->targets)
        ->toBeEmpty();
});
