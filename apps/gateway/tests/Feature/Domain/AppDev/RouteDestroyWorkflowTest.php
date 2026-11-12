<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitRouteOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\NativeRouteRemovalProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process as ManagedProcess;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-public-root');
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });
});

function destroyNode(string $name, string $address): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $address, 'public_ssh_host' => $address, 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-key',
    ]));
}

/** @return array{Node, Node} */
function destroyFleet(): array
{
    $control = destroyNode('control', '10.44.0.2');
    $control->roles()->create(['role' => 'swamp', 'status' => 'active']);
    $node = destroyNode('workload', '10.44.0.9');
    foreach (['app-dev', 'vpn'] as $role) {
        $node->roles()->create(['role' => $role, 'status' => 'active']);
    }

    return [$node, $control];
}

function destroyFixture(Node $node, string $kind, string $suffix): Route
{
    $project = $kind === 'proxy' ? null : Project::query()->create(['name' => $suffix, 'slug' => $suffix, 'type' => 'laravel-app',
        'repository_url' => 'https://github.com/example/fixture.git', 'default_branch' => 'main', 'root' => 'public', 'php_version' => '8.5']);
    $route = Route::query()->create([
        'kind' => $kind === 'proxy' ? 'custom_proxy' : 'app', 'node_id' => $node->id, 'project_id' => $project?->id,
        'domain' => $suffix.'.outside.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending',
    ]);
    $instance = null;
    if ($kind === 'proxy') {
        $route->customProxy()->create(['node_id' => $node->id, 'upstream' => 'http://127.0.0.1:8080']);
    } else {
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $suffix,
            'checkout_path' => '/apps/'.$suffix, 'selected_php_version' => '8.5', 'status' => 'source_resolved']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    }
    if ($kind === 'gone') {
        $cluster = Cluster::query()->create(['name' => 'fixture-cluster', 'tld' => 'cluster.test']);
        $node->update(['cluster_id' => $cluster->id]);
        $node->roles()->create(['role' => 'router', 'status' => 'active', 'cluster_id' => $cluster->id]);
        $route->update(['node_id' => null, 'cluster_id' => $cluster->id]);
    }
    $route->update(['status' => 'active', 'sites_published' => true]);
    if ($kind === 'active') {
        $instance->update(['status' => 'active']);
    } elseif ($kind === 'gone') {
        $instance->delete();
        $route->unsetRelation('targets');
    }

    return $route;
}

/** @param array<string,mixed> $inputs @return array<string,mixed> */
function destroyExpressions(array $inputs): array
{
    $process = new Process(['deno', 'run', '--allow-read', base_path('tests/Support/RouteDestroyExpressions.ts')], base_path('../..'));
    $process->setInput(json_encode(['inputs' => $inputs], JSON_THROW_ON_ERROR));
    $process->setTimeout(10);
    $process->mustRun();

    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array{Operation,array<string,mixed>} */
function destroyAdmission(Route $route): array
{
    $operation = app(AdmitRouteOperationAction::class)->destroy($route);
    $operation->update(['status' => OperationStatus::Running, 'state' => [...$operation->state, 'swamp_run_id' => 'fixture-run']]);
    $input = app(NativeRouteRemovalProjection::class)->input($operation);

    return [$operation, ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'route.destroy', ...$input]];
}

it('renders every instantiated destroy and child CEL expression from a real :dataset capture', function (string $kind): void {
    [$node, $control] = destroyFleet();
    $route = destroyFixture($node, $kind, $kind);
    if ($kind === 'gone') {
        expect(Instance::query()->where('project_id', $route->project_id)->exists())->toBeFalse()
            ->and($route->targets()->exists())->toBeFalse();
    }
    [$operation, $inputs] = destroyAdmission($route);
    $plan = $inputs['intent'];
    foreach ($plan['nodes'] as $connection) {
        expect($connection['control_node_id'])->toBe($control->id);
    }
    $run = destroyExpressions($inputs);
    expect($run['status'])->toBe('succeeded')
        ->and($run['expressions'])->toBeGreaterThan(20)
        ->and($run['files'])->toContain('operation-route-removal');
    if ($kind === 'proxy') {
        expect($run['files'])->toContain('route-target-retire', 'route-create-site', 'route-create-dns', 'route-dns-grace')
            ->and($run['dnsRemoved'])->toBeTrue()->and($run['siteRemoved'])->toBeTrue()
            ->and($run['certificateInputs'])->not->toBeEmpty();
        $trace = implode("\n", $run['trace']);
        expect(strpos($trace, 'route-create-dns/restart:'))->toBeLessThan(strpos($trace, 'route-dns-grace/'))
            ->and(strpos($trace, 'route-dns-grace/'))->toBeLessThan(strpos($trace, 'route-create-site/site:'));
    } else {
        expect($plan['cleanup'])->not->toBeEmpty()
            ->and($plan['retire_nodes'])->toContain($node->id)
            ->and($run['files'])->toContain('route-target-retire', 'route-certificate-remove')
            ->and($run['certificateInputs'])->not->toBeEmpty();
    }
    app(NativeRouteRemovalProjection::class)->apply($operation, [
        'run_id' => 'fixture-run', 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => $run['steps']],
    ]);
    expect(Route::query()->find($route->id))->toBeNull();
})->with(['proxy', 'gone']);

it('retires certificates from an older captured plan without inventing a control Node', function (): void {
    [$node] = destroyFleet();
    $route = destroyFixture($node, 'proxy', 'older-plan');
    [, $inputs] = destroyAdmission($route);
    foreach ($inputs['intent']['nodes'] as &$connection) {
        unset($connection['control_node_id']);
    }
    unset($connection);
    $run = destroyExpressions($inputs);
    foreach ($run['certificateInputs'] as $input) {
        expect(array_key_exists('control_node_id', $input))->toBeFalse();
    }
    expect($run['status'])->toBe('succeeded')
        ->and($run['files'])->toContain('route-certificate-remove')
        ->and($run['certificateInputs'])->not->toBeEmpty()
        ->and($run['trace'])->toContain('route-certificate-remove/Remove the Caddy pair:succeeded',
            'route-certificate-remove/Remove the account pair:succeeded');
});

it('captures and evaluates a public Process proxy with a genuinely cleanup-only Process Node', function (): void {
    [$ingress, $control] = destroyFleet();
    $cluster = Cluster::query()->create(['name' => 'public-process', 'state' => 'active', 'tld' => 'cluster.test']);
    $ingress->update(['cluster_id' => $cluster->id]);
    $ingress->roles()->create(['role' => 'ingress', 'status' => 'active', 'cluster_id' => $cluster->id]);
    $processNode = destroyNode('process', '10.44.0.10');
    $process = ManagedProcess::query()->create([
        'owner_type' => Node::class, 'owner_id' => $processNode->id, 'name' => 'fixture-proxy',
        'runtime' => 'docker', 'runtime_config' => ['image' => 'nginx:alpine', 'ports' => ['10.44.0.10:18080:8080/tcp']],
        'desired_state' => 'running', 'status' => 'active',
    ]);
    $route = Route::query()->create([
        'kind' => 'custom_proxy', 'cluster_id' => $cluster->id, 'domain' => 'public-process.outside.test',
        'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending',
    ]);
    $route->customProxy()->create(['node_id' => $processNode->id, 'process_id' => $process->id, 'upstream' => 'http://10.44.0.10:18080']);
    $route->update(['status' => 'active', 'sites_published' => true, 'replacement_step' => 'cleanup']);
    expect(app(DevelopmentSiteRepository::class)->all()->where('domain', $route->domain)->pluck('nodeId')->unique()->values()->all())
        ->toBe([$ingress->id]);

    [, $inputs] = destroyAdmission($route);
    $plan = $inputs['intent'];
    expect(array_column($plan['retire']['caddy'], 'node_id'))->toContain($ingress->id)->not->toContain($processNode->id)
        ->and(array_column($plan['dns'], 'node_id'))->not->toContain($processNode->id)
        ->and(array_column($plan['cleanup'], 'node_id'))->toContain($processNode->id)
        ->and($plan['nodes'][(string) $processNode->id]['host'])->toBe($processNode->wireguard_ip)
        ->and($plan['nodes'][(string) $processNode->id]['control_node_id'])->toBe($control->id);

    $run = destroyExpressions($inputs);
    $processCleanup = array_values(array_filter($run['certificateInputs'], static fn (array $input): bool => $input['node_id'] === $processNode->id));
    expect($run['status'])->toBe('succeeded')
        ->and($processCleanup)->not->toBeEmpty()
        ->and($processCleanup[0]['connection']['nodeHost'])->toBe($processNode->wireguard_ip)
        ->and($processCleanup[0]['leaf']['scope'])->toBe('route-'.$route->id)
        ->and($run['trace'])->toContain('route-certificate-remove/Remove the Caddy pair:succeeded',
            'route-certificate-remove/Remove the account pair:succeeded');
});

it('still refuses destruction of an active Instances Route before starting work', function (): void {
    [$node] = destroyFleet();
    $route = destroyFixture($node, 'active', 'owned');
    $error = null;
    try {
        app(AdmitRouteOperationAction::class)->destroy($route);
    } catch (ResourceOperationException $failure) {
        $error = $failure;
    }
    expect($error)->toBeInstanceOf(ResourceOperationException::class)
        ->and($error?->errorCode)->toBe('route.target_conflict');
    expect(Operation::query()->where('kind', 'route.destroy')->exists())->toBeFalse();
});
