<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('derives the default runtime from the primary instance node, not a stale app node_id', function (): void {
    // The concrete instance is placed on a macOS node, which is the placement
    // authority for the default runtime.
    $macNode = Node::factory()->create(['name' => 'mac-host', 'platform' => 'darwin', 'user' => 'nckrtl']);

    $app = App::factory()->create([
        'name' => 'docs',
        'runtime' => AppRuntimeKind::Php,
    ]);
    Instance::factory()->for($app, 'app')->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $macNode->id,
            node: $macNode->name,
            path: '/Users/nckrtl/apps/docs',
            document_root: 'public',
        ),
    ]);

    expect(ProcessRuntime::defaultForApp($app))->toBe(ProcessRuntime::Launchd);
});

it('falls back to systemd when the primary instance node is not macOS', function (): void {
    $linuxNode = Node::factory()->create(['name' => 'linux-host', 'platform' => 'ubuntu_24-04']);

    $app = App::factory()->create([
        'name' => 'docs',
        'runtime' => AppRuntimeKind::Php,
    ]);
    Instance::factory()->for($app, 'app')->create([
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $linuxNode->id,
            node: $linuxNode->name,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
        ),
    ]);

    expect(ProcessRuntime::defaultForApp($app))->toBe(ProcessRuntime::Systemd);
});
