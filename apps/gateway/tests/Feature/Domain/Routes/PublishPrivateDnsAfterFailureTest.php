<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\NativeClusterRouterProjection;
use App\Jobs\AppDev\PublishPrivateDns;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/** A running cluster.router_set Operation whose run restored the DNS it changed. */
function clusterRouterSetOperation(string $kind = 'cluster.router_set'): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'router', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.5',
        'wireguard_ip' => '10.44.0.5', 'user' => 'orbit']));

    return Operation::query()->create(['kind' => $kind, 'target_type' => 'cluster', 'target_id' => 1, 'node_id' => $node->id,
        'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'state' => ['swamp_run_id' => 'fixture-router'],
        'input' => ['cluster_router' => ['candidate' => null, 'old_nodes' => []]]]);
}

/** @return array<string, mixed> */
function clusterRouterTerminal(string $status): array
{
    return ['run_id' => 'fixture-router', 'status' => $status, 'started_at' => now()->subSeconds(5)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'failure' => ['failed_step' => 'Probe the workloads-0', 'detail' => 'a probe failed', 'stderr_recorded' => true],
        'result' => ['steps' => ['Restore the before state' => ['status' => 'succeeded']]]];
}

describe('Private DNS after a failed run', function (): void {
    it('publishes a fresh render once a failed Router change is projected, so a restore that a newer publication superseded is corrected', function (): void {
        Queue::fake();
        $operation = clusterRouterSetOperation();

        $projected = app(NativeClusterRouterProjection::class)->apply($operation, clusterRouterTerminal('failed'));

        expect($projected->status)->toBe(OperationStatus::Failed);
        Queue::assertPushed(PublishPrivateDns::class, 1);
    });

    it('publishes nothing more after a succeeded change', function (): void {
        Queue::fake();
        $operation = clusterRouterSetOperation();

        $projected = app(NativeClusterRouterProjection::class)->apply($operation, [...clusterRouterTerminal('succeeded'), 'failure' => null]);

        expect($projected->status)->toBe(OperationStatus::Succeeded);
        Queue::assertNotPushed(PublishPrivateDns::class);
    });

    it('publishes once for a terminal result that arrives twice', function (): void {
        Queue::fake();
        $operation = clusterRouterSetOperation();
        $projection = app(NativeClusterRouterProjection::class);

        $projection->apply($operation, clusterRouterTerminal('failed'));
        $projection->apply($operation, clusterRouterTerminal('failed'));

        Queue::assertPushed(PublishPrivateDns::class, 1);
    });

    it('renders and publishes again when the job runs', function (): void {
        $dns = new class implements PrivateDnsManager
        {
            public int $converged = 0;

            public function converge(): void
            {
                $this->converged++;
            }
        };
        app()->instance(PrivateDnsManager::class, $dns);

        new PublishPrivateDns()->handle(app(PrivateDnsManager::class));

        expect($dns->converged)->toBe(1);
    });
});
