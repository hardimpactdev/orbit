<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Models\UpdateLease;
use App\Services\Operations\FleetUpdateVerifier;
use App\Services\Operations\GatewayServiceUpdater;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\UpdateLeaseManager;
use App\Services\Operations\UpdateRunner;
use App\Services\Operations\WorkloadNodeUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('updates active non-gateway app-role nodes from the persisted manifest snapshot', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RemoteShell::class, $shell);

    $run = workloadUpdaterRun();
    $appDev = Node::factory()->appDev()->create([
        'name' => 'app-dev-1',
        'platform' => 'linux',
        'orbit_path' => '/opt/orbit-app-dev',
    ]);
    $appProd = Node::factory()->appProd()->create([
        'name' => 'app-prod-1',
        'platform' => 'linux-amd64',
        'orbit_path' => '/opt/orbit-app-prod',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $appProd->id,
        'role' => 'websocket',
        'status' => 'active',
    ]);
    Node::factory()->database()->create(['name' => 'database-1']);
    Node::factory()->gateway()->appDev()->create(['name' => 'gateway-app']);

    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            targetVersion: '2.0.0',
            cliArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-linux-amd64',
                    'sha256' => str_repeat('e', 64),
                ],
            ],
            roleImages: [
                'orbit-caddy' => 'caddy:2.9-alpine',
                'orbit-websocket' => 'ghcr.io/hardimpactdev/orbit-websocket:2.0.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
            ],
        ),
    );

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)->toMatchArray([
        [
            'target' => 'app-dev-1',
            'node' => 'app-dev-1',
            'role' => 'app-dev',
            'status' => 'completed',
        ],
        [
            'target' => 'app-prod-1',
            'node' => 'app-prod-1',
            'role' => 'app-prod',
            'status' => 'completed',
        ],
    ])
        ->and($shell->calls)->toHaveCount(2)
        ->and($shell->activeLeases)->toBe([
            'app-dev-1' => ['node:app-dev-1'],
            'app-prod-1' => ['node:app-prod-1'],
        ])
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())->toBe(0);

    expect($shell->scriptFor('app-dev-1'))
        ->toContain('download_cli')
        ->toContain('install_cli')
        ->toContain('verify_cli')
        ->toContain('pull_required_images')
        ->toContain('https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-linux-amd64')
        ->toContain(str_repeat('e', 64))
        ->toContain("docker pull 'caddy:2.9-alpine'")
        ->not->toContain('orbit-websocket:2.0.0');

    expect($shell->scriptFor('app-prod-1'))
        ->toContain("docker pull 'caddy:2.9-alpine'")
        ->toContain("docker pull 'ghcr.io/hardimpactdev/orbit-websocket:2.0.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff'");
});

it('continues updating later workload nodes when one remote update fails', function (): void {
    $shell = new WorkloadUpdaterFakeShell(failures: [
        'app-dev-1' => new RemoteShellResult(exitCode: 12, stdout: '', stderr: 'download failed', durationMs: 10),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    Node::factory()->appProd()->create(['name' => 'app-prod-1', 'platform' => 'linux']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot());

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)->toMatchArray([
        [
            'target' => 'app-dev-1',
            'node' => 'app-dev-1',
            'role' => 'app-dev',
            'status' => 'failed',
            'failed_step' => 'remote_update',
            'output' => 'download failed',
        ],
        [
            'target' => 'app-prod-1',
            'node' => 'app-prod-1',
            'role' => 'app-prod',
            'status' => 'completed',
        ],
    ])
        ->and($shell->calls)->toHaveCount(2)
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())->toBe(0);
});

it('records a failed target for an active node lease conflict and updates the remaining nodes', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RemoteShell::class, $shell);

    $run = workloadUpdaterRun();
    $conflictingNode = Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    Node::factory()->appProd()->create(['name' => 'app-prod-1', 'platform' => 'linux']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot());
    $otherRun = workloadUpdaterRun();

    app(UpdateLeaseManager::class)->acquire(
        resourceType: 'node',
        resourceKey: $conflictingNode->name,
        operationRun: $otherRun,
        ownerToken: 'other-owner',
        ttlSeconds: 300,
    );

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results[0])->toMatchArray([
        'target' => 'app-dev-1',
        'node' => 'app-dev-1',
        'role' => 'app-dev',
        'status' => 'failed',
        'failed_step' => 'node_lease',
    ])
        ->and($results[0]['output'])->toContain('Update resource [node:app-dev-1]')
        ->and($results[1])->toMatchArray([
            'target' => 'app-prod-1',
            'node' => 'app-prod-1',
            'role' => 'app-prod',
            'status' => 'completed',
        ])
        ->and($shell->calls)->toHaveCount(1)
        ->and($shell->calls[0]['node'])->toBe('app-prod-1')
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->pluck('resource_key')->all())->toBe(['app-dev-1']);
});

it('is invoked by the default update runner pipeline while the fleet lease is active', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(GatewayServiceUpdater::class, new WorkloadUpdaterNoopGatewayUpdater);
    app()->instance(FleetUpdateVerifier::class, new WorkloadUpdaterNoopFleetVerifier);

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot());

    app(UpdateRunner::class)->run($run->id);

    expect($shell->calls)->toHaveCount(1)
        ->and($shell->activeLeases)->toBe([
            'app-dev-1' => ['fleet:update-all', 'node:app-dev-1'],
        ])
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())->toBe(0);
});

function workloadUpdaterRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

class WorkloadUpdaterNoopGatewayUpdater extends GatewayServiceUpdater
{
    #[Override]
    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        //
    }
}

final class WorkloadUpdaterNoopFleetVerifier extends FleetUpdateVerifier
{
    public function __construct() {}

    #[Override]
    public function verify(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        //
    }
}

/**
 * @param  array<string, array{url: string, sha256: string}>  $cliArtifacts
 * @param  array<string, string>  $roleImages
 */
function workloadUpdaterSnapshot(
    string $targetVersion = '1.2.3',
    string $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    array $cliArtifacts = [],
    array $roleImages = [],
): OperationUpdatePlanSnapshot {
    $cliArtifacts = $cliArtifacts === [] ? [
        'linux-amd64' => [
            'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-linux-amd64',
            'sha256' => str_repeat('b', 64),
        ],
    ] : $cliArtifacts;
    $roleImages = $roleImages === [] ? [
        'orbit-caddy' => 'caddy:2-alpine',
        'orbit-websocket' => 'ghcr.io/hardimpactdev/orbit-websocket:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
    ] : $roleImages;

    return new OperationUpdatePlanSnapshot(
        targetVersion: $targetVersion,
        gatewayImage: $gatewayImage,
        manifestSource: 'github-release',
        manifestVersion: $targetVersion,
        manifestSnapshot: [
            'version' => $targetVersion,
            'source' => 'github-release',
            'images' => [
                'gateway' => $gatewayImage,
            ],
            'cli_artifacts' => $cliArtifacts,
            'role_images' => $roleImages,
        ],
        cliArtifacts: $cliArtifacts,
        roleImages: $roleImages,
    );
}

final class WorkloadUpdaterFakeShell implements RemoteShell
{
    /**
     * @var list<array{node: string, script: string, options: array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * @var array<string, list<string>>
     */
    public array $activeLeases = [];

    /**
     * @param  array<string, RemoteShellResult>  $failures
     */
    public function __construct(
        private array $failures = [],
    ) {}

    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node->name,
            'script' => $script,
            'options' => $options,
        ];
        $this->activeLeases[$node->name] = UpdateLease::query()
            ->whereNotNull('active_resource_key')
            ->orderBy('id')
            ->get()
            ->map(fn (UpdateLease $lease): string => "{$lease->resource_type}:{$lease->resource_key}")
            ->all();

        return $this->failures[$node->name]
            ?? new RemoteShellResult(exitCode: 0, stdout: "updated\n", stderr: '', durationMs: 20);
    }

    public function scriptFor(string $node): string
    {
        foreach ($this->calls as $call) {
            if ($call['node'] === $node) {
                return $call['script'];
            }
        }

        throw new RuntimeException("No script recorded for [{$node}].");
    }
}
