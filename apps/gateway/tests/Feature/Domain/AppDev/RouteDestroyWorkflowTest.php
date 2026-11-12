<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitRouteOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\NativeRouteRemovalProjection;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
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

/** @return array{Node, Node} */
function destroyFleet(): array
{
    $make = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $address, 'public_ssh_host' => $address, 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-key',
    ]));
    $control = $make('control', '10.44.0.2');
    $control->roles()->create(['role' => 'swamp', 'status' => 'active']);
    $node = $make('workload', '10.44.0.9');
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
    $route->update(['status' => 'active', 'sites_published' => true]);
    if ($kind === 'active') {
        $instance->update(['status' => 'active']);
    }

    return $route;
}

/** @param array<string,mixed> $inputs @return array<string,mixed> */
function destroyExpressions(array $inputs, bool $fail = false): array
{
    $process = new Process(['deno', 'run', '--allow-read', base_path('tests/Support/RouteDestroyExpressions.ts')], base_path('../..'));
    $process->setInput(json_encode(['inputs' => $inputs, 'fail_publication' => $fail], JSON_THROW_ON_ERROR));
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

it('renders every instantiated destroy and child CEL expression from real Gateway captures', function (): void {
    [$node, $control] = destroyFleet();
    foreach (['proxy', 'inactive'] as $kind) {
        $route = destroyFixture($node, $kind, $kind);
        [$operation, $inputs] = destroyAdmission($route);
        $plan = $inputs['intent'];
        expect($plan['nodes'][(string) $node->id]['control_node_id'])->toBe($control->id);
        $run = destroyExpressions($inputs);
        expect($run['status'])->toBe('succeeded')
            ->and($run['expressions'])->toBeGreaterThan(20)
            ->and($run['files'])->toContain('route-target-retire', 'route-certificate-remove')
            ->and($run['dnsRemoved'])->toBeTrue();
        app(NativeRouteRemovalProjection::class)->apply($operation, [
            'run_id' => 'fixture-run', 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
            'result' => ['steps' => $run['steps']],
        ]);
        expect(Route::query()->find($route->id))->toBeNull();

    }
});

it('keeps DNS when publication refuses and records the incomplete withdrawal truthfully', function (): void {
    [$node] = destroyFleet();
    $route = destroyFixture($node, 'proxy', 'failure');
    [$operation, $inputs] = destroyAdmission($route);
    $run = destroyExpressions($inputs, true);
    expect($run['status'])->toBe('failed')->and($run['publicationStarted'])->toBeTrue()
        ->and($run['siteRemoved'])->toBeFalse()->and($run['dnsRemoved'])->toBeFalse();
    app(NativeRouteRemovalProjection::class)->apply($operation, [
        'run_id' => 'fixture-run', 'status' => 'failed', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'failure' => ['failed_step' => 'Withdraw site, remove cert-0'], 'result' => ['steps' => $run['steps']],
    ]);
    expect($route->refresh()->status)->toBe(RouteStatus::Retiring)
        ->and($route->sites_published)->toBeTrue()->and($route->failed_step)->toBe('Withdraw site, remove cert')
        ->and($route->error_code)->toBe('route.withdraw_failed');

    [$retry, $retryInputs] = destroyAdmission($route);
    $completed = destroyExpressions($retryInputs);
    expect($completed['status'])->toBe('succeeded')
        ->and($completed['siteRemoved'])->toBeTrue()->and($completed['dnsRemoved'])->toBeTrue();
    app(NativeRouteRemovalProjection::class)->apply($retry, [
        'run_id' => 'fixture-run', 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => $completed['steps']],
    ]);
    expect(Route::query()->find($route->id))->toBeNull();

});

it('marks confirmed site removal unpublished if the final DNS step fails', function (): void {
    [$node] = destroyFleet();
    $route = destroyFixture($node, 'proxy', 'dns-failure');
    [$operation, $inputs] = destroyAdmission($route);
    $run = destroyExpressions($inputs);
    app(NativeRouteRemovalProjection::class)->apply($operation, [
        'run_id' => 'fixture-run', 'status' => 'failed', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'failure' => ['failed_step' => 'Remove private DNS-0'], 'result' => ['steps' => $run['steps']],
    ]);
    expect($route->refresh()->status)->toBe(RouteStatus::Retiring)->and($route->sites_published)->toBeFalse()
        ->and($route->error_code)->toBe('private_dns.publication_failed');
});

it('still refuses destruction of an active Instances Route before starting work', function (): void {
    [$node] = destroyFleet();
    $route = destroyFixture($node, 'active', 'owned');
    expect(fn () => app(AdmitRouteOperationAction::class)->destroy($route))
        ->toThrow(ResourceOperationException::class, 'must remain associated');
    expect(Operation::query()->where('kind', 'route.destroy')->exists())->toBeFalse();
});

it('admits an inactive Instances active Route for destruction', function (): void {
    [$node] = destroyFleet();
    $route = destroyFixture($node, 'inactive', 'detached');
    [$operation] = destroyAdmission($route);
    expect($operation->kind)->toBe('route.destroy')->and($operation->target_id)->toBe($route->id);
});
