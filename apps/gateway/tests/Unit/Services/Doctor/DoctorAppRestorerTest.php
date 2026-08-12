<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Doctor\DoctorAppRestorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns null when an app instance belongs to a different node', function (): void {
    $appNode = Node::factory()->create(['name' => 'app-node']);
    $instanceNode = Node::factory()->create(['name' => 'instance-node']);
    $app = App::factory()->for($appNode, 'node')->create(['name' => 'docs']);

    Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $instanceNode->id),
    ]);

    expect(app(DoctorAppRestorer::class)->apply(
        $appNode,
        'app.runtime_config_missing',
        ['app' => 'docs', 'instance' => 'production'],
    ))->toBeNull();
});

it('preserves runtime config extra success and failure actions', function (
    RemoteShellResult $result,
    array $expected,
): void {
    $node = Node::factory()->create(['name' => 'app-node']);
    app()->instance(RemoteShell::class, new class($result) implements RemoteShell {
        public function __construct(
            private readonly RemoteShellResult $result,
        ) {}

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return $this->result;
        }
    });

    $action = app(DoctorAppRestorer::class)->apply(
        $node,
        'app.runtime_config_extra',
        ['app' => 'orphan'],
    );

    expect($action)->toMatchArray($expected);
})->with([
    'proven absent' => [
        new RemoteShellResult(0, "orbit-container-config-probe:absent\n", '', 1),
        [
            'family' => 'app',
            'node' => 'app-node',
            'key' => 'app.runtime_config_extra',
            'mode' => 'fix',
            'status' => 'completed',
            'details' => [
                'app' => 'orphan',
                'path' => '/home/orbit/.config/orbit/apps/orphan.ini',
                'outcome' => 'already_absent',
            ],
        ],
    ],
    'probe failed' => [
        new RemoteShellResult(1, '', 'ssh failed', 1),
        [
            'family' => 'app',
            'node' => 'app-node',
            'key' => 'app.runtime_config_extra',
            'mode' => 'restore',
            'status' => 'failed',
            'summary' => 'Failed to remove extra managed app runtime config for orphan.',
            'details' => [
                'app' => 'orphan',
                'error' => "Failed to remove managed runtime config for 'orphan' on app-node.",
            ],
        ],
    ],
]);
