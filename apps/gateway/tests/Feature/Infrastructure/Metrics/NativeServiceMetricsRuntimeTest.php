<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeServiceMetricsRuntime;
use App\Infrastructure\Metrics\ServiceMetricsNode;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Symfony\Component\Process\Process;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeNodeCaddyBuilds;

/**
 * The full exporter state that `metrics.service-runtime` returns and accepts.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function service_metrics_state(array $overrides = []): array
{
    return [...['config' => null, 'unit' => null, 'rules' => [], 'active' => false, 'enabled' => false, 'binary' => false], ...$overrides];
}

beforeEach(function (): void {
    $this->builds = new FakeNodeCaddyBuilds;
    $this->pools = [];
    $this->exporter = service_metrics_state();
    $this->agents = new FakeAgentCommandRunner()
        ->answer('php.inspect-production-pool', fn (): array => array_shift($this->pools))
        ->answer('metrics.service-runtime', fn (array $input): array => $input['operation'] === 'snapshot' ? $this->exporter : []);
    $this->productionPhp = Mockery::mock(ProductionPhpRuntimeManager::class)->shouldIgnoreMissing();
    $this->runtime = new NativeServiceMetricsRuntime(
        $this->builds,
        $this->agents,
        productionPhp: $this->productionPhp,
    );
    $this->metrics = service_metrics_runtime_node('metrics', '10.44.0.7', []);
});

it('requests a Node Caddy build of an Ingress Node and writes no Caddy file', function (): void {
    $ingress = service_metrics_runtime_node('app-prod', '10.44.0.4', [RoleName::Ingress, RoleName::AppProd]);

    $this->runtime->converge(new ServiceMetricsNode($ingress, true, false), $this->metrics);

    expect($this->builds->built)->toBe(['app-prod'])
        ->and($this->agents->calls[0])->toMatchArray([
            'node' => 'app-prod',
            'command' => 'metrics.service-runtime',
            'input' => ['operation' => 'apply', 'state' => [
                'config' => null, 'unit' => null, 'active' => false, 'enabled' => false, 'binary' => false,
                'rules' => [
                    ['allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.7', 'to', '10.44.0.4', 'port', '9103', 'comment', 'orbit:metrics-service-caddy-allow'],
                    ['deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to', '10.44.0.4', 'port', '9103', 'comment', 'orbit:metrics-service-caddy-deny'],
                ],
            ]],
        ])
        ->and(json_encode($this->agents->calls, JSON_THROW_ON_ERROR))
        ->not->toContain('orbit-versions', '00-metrics-service.caddy', '/etc/caddy/Caddyfile');
});

it('builds no Node that cannot render the scrape site', function (): void {
    $workload = service_metrics_runtime_node('app-dev', '10.44.0.3', [RoleName::AppDev]);

    $this->runtime->converge(new ServiceMetricsNode($workload, false, false), $this->metrics);

    expect($this->builds->built)->toBe([]);
});

it('snapshots exporter state only and restores by building the Node', function (): void {
    $ingress = service_metrics_runtime_node('app-prod', '10.44.0.4', [RoleName::Ingress]);
    $target = new ServiceMetricsNode($ingress, true, false);

    $snapshot = $this->runtime->snapshot($target);
    $commandsBeforeRestore = count($this->agents->calls);
    $this->runtime->restore($target, $snapshot);

    expect(json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR))->toBe(['exporter' => service_metrics_state(), 'pools' => []])
        ->and(json_encode($this->agents->calls, JSON_THROW_ON_ERROR))->not->toContain('/etc/caddy')
        ->and(count($this->agents->calls))->toBe($commandsBeforeRestore + 1)
        ->and($this->builds->built)->toBe(['app-prod']);
});

it('rejects snapshots without valid exporter state', function (): void {
    $ingress = service_metrics_runtime_node('app-prod', '10.44.0.4', [RoleName::Ingress]);

    expect(fn () => $this->runtime->restore(new ServiceMetricsNode($ingress, true, false), '42'))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('metrics.service_inspection_failed'))
        ->and(fn () => $this->runtime->restore(new ServiceMetricsNode($ingress, true, false), json_encode([], JSON_THROW_ON_ERROR)))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('metrics.service_inspection_failed'))
        ->and(fn () => $this->runtime->restore(new ServiceMetricsNode($ingress, true, false), json_encode(['exporter' => null], JSON_THROW_ON_ERROR)))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('metrics.service_inspection_failed'));
});

it('restores the previous pool monitoring state after publication fails', function (): void {
    $node = service_metrics_runtime_node('app-prod', '10.44.0.4', [RoleName::AppProd]);
    $instance = service_metrics_runtime_instance($node);
    $target = new ServiceMetricsNode($node, false, false, [$instance]);
    $enabledPool = "[orbit-metricapp]\nuser = metricapp\npm.status_path = /orbit-fpm-status\npm.status_listen = /run/php/metricapp.sock.status\n";
    $disabledPool = "[orbit-metricapp]\nuser = metricapp\n";
    $this->exporter = service_metrics_state(['config' => 'prior-exporter-config']);
    $this->pools = [['pool' => $enabledPool, 'fingerprint' => 'before'], ['pool' => $disabledPool, 'fingerprint' => 'after']];
    $snapshot = $this->runtime->snapshot($target);
    $this->productionPhp->shouldReceive('convergeMonitoring')->once()->with($instance, false);
    $this->productionPhp->shouldReceive('convergeMonitoring')->once()->with($instance, true);

    try {
        $this->runtime->converge($target, $this->metrics);
        throw new RuntimeException('Prometheus publication failed.');
    } catch (RuntimeException $failure) {
        expect($failure->getMessage())->toBe('Prometheus publication failed.');
        $this->runtime->restore($target, $snapshot);
    }

    expect(json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['pools'][(string) $instance->id]['enabled'])->toBeTrue()
        ->and($this->agents->calls[array_key_last($this->agents->calls)]['input'])
        ->toMatchArray(['operation' => 'apply', 'state' => service_metrics_state(['config' => 'prior-exporter-config'])]);
});

it('includes a read-only runtime fingerprint in exporter config after monitoring convergence', function (): void {
    $node = service_metrics_runtime_node('app-prod', '10.44.0.4', [RoleName::AppProd]);
    $instance = service_metrics_runtime_instance($node);
    $this->productionPhp->shouldReceive('convergeMonitoring')->once()->with($instance, true);
    $this->pools = [['pool' => "[orbit-metricapp]\nuser = metricapp\npm.status_path = /orbit-fpm-status\npm.status_listen = /run/php/metricapp.sock.status\n", 'fingerprint' => 'local-tuning-hash']];

    $this->runtime->converge(new ServiceMetricsNode($node, false, true, [$instance]), $this->metrics);
    $request = $this->agents->calls[array_key_last($this->agents->calls)]['input'];

    expect($request['state']['config'])->toContain('# runtime '.$instance->id.': local-tuning-hash');
});

it('restores exporter state without asking the exporter command to restore pool state', function (): void {
    $ingress = service_metrics_runtime_node('app-prod', '10.44.0.4', [RoleName::Ingress]);
    $this->exporter = service_metrics_state(['enabled' => true]);
    $snapshot = $this->runtime->snapshot(new ServiceMetricsNode($ingress, true, false));
    $state = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
    $commandsBeforeRestore = count($this->agents->calls);

    $this->runtime->restore(new ServiceMetricsNode($ingress, true, false), $snapshot);

    expect($state)->toBe(['exporter' => service_metrics_state(['enabled' => true]), 'pools' => []])
        ->and(count($this->agents->calls))->toBe($commandsBeforeRestore + 1)
        ->and($this->agents->calls[$commandsBeforeRestore]['input'])
        ->toMatchArray(['operation' => 'apply', 'state' => service_metrics_state(['enabled' => true])])
        ->and($this->builds->built)->toBe(['app-prod']);
});

it('keeps its error code and names the build stage when the build fails', function (): void {
    $ingress = service_metrics_runtime_node('app-prod', '10.44.0.4', [RoleName::Ingress]);
    $this->builds->failNext('app-prod', 'addresses', 'The build binds 192.168.6.30, which is not an address on this Node.');

    expect(fn () => $this->runtime->converge(new ServiceMetricsNode($ingress, true, false), $this->metrics))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('metrics.caddy_publication_failed')
                ->and($exception->step)->toBe('service-metrics-caddy')
                ->and($exception->getMessage())->toContain('failed at stage [addresses]');
        });
});

it('maps an agent refusal to the service metrics step before requesting a Caddy build', function (): void {
    $ingress = service_metrics_runtime_node('app-prod', '10.44.0.4', [RoleName::Ingress]);
    $this->agents->refuse('metrics.service-runtime', 'metrics.service_ownership', ['path' => '/etc/orbit/service-metrics']);

    expect(fn () => $this->runtime->converge(new ServiceMetricsNode($ingress, true, false), $this->metrics))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('metrics.service_convergence_failed')
                ->and($exception->step)->toBe('service-metrics');
        });
    expect($this->builds->built)->toBe([]);
});

arch('service metrics runs Node code only through the agent command runner')
    ->expect(NativeServiceMetricsRuntime::class)
    ->not->toUse([
        SshExecutor::class,
        RemoteCommand::class,
        Process::class,
    ]);

/** @param list<RoleName> $roles */
function service_metrics_runtime_node(string $name, string $address, array $roles): Node
{
    $cluster = in_array(RoleName::Ingress, $roles, true)
        ? Cluster::query()->create(['name' => "{$name}-cluster", 'state' => ClusterState::Active])
        : null;
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'user' => 'orbit',
        'wireguard_ip' => $address,
        'cluster_id' => $cluster?->id,
    ]);

    foreach ($roles as $role) {
        $node->roles()->create([
            'role' => $role,
            'status' => LifecycleStatus::Active,
            'cluster_id' => $role === RoleName::Ingress ? $cluster?->id : null,
        ]);
    }

    return $node;
}

function service_metrics_runtime_instance(Node $node): Instance
{
    $node->roles()->firstOrCreate(
        ['role' => RoleName::AppProd],
        ['status' => LifecycleStatus::Active],
    );
    $project = Project::query()->create([
        'name' => 'Metrics fixture',
        'slug' => 'metrics-fixture',
        'repository_url' => 'https://example.test/metrics-fixture.git',
    ]);

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/metricapp/current',
        'root' => 'public',
        'production_user' => 'metricapp',
        'production_home' => '/home/metricapp',
        'selected_php_version' => '8.5',
        'production_php_service' => 'orbit-metricapp-php8.5-fpm.service',
        'production_php_pool' => 'orbit-metricapp',
        'production_php_socket' => '/run/php/metricapp.sock',
        'status' => 'active',
    ]);
}
