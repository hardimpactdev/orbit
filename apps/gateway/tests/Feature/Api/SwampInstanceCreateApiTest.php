<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectNodeExclusion;
use App\Models\Route;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), [
        ...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
    ]));
    $this->node = Node::query()->create(['name' => 'admission-dev', 'user' => 'orbit', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'status' => 'active', 'tld' => 'test',
        'settings' => ['apps' => ['path' => '/srv/apps']]]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->node->accessibleNodes()->attach($this->node);
    $operator = Node::query()->create(['name' => 'admission-operator', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2', 'status' => 'active']);
    $operator->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->project = Project::query()->create(['name' => 'Admission', 'slug' => 'admission', 'type' => 'laravel-app',
        'root' => 'public', 'repository_url' => 'https://github.com/example/admission.git', 'default_branch' => 'main']);
    $this->payload = ['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'proof'];
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
    $this->destinations = Mockery::mock(InstanceDestinationGuard::class);
    app()->instance(InstanceDestinationGuard::class, $this->destinations);
    Queue::fake();
    $this->withServerVariables(['REMOTE_ADDR' => $operator->wireguard_ip]);
});

afterEach(function (): void {
    Queue::assertNothingPushed();
});

it('reserves the private Route and exact target association before any source effect', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'create-private-route')->postJson('/api/v1/instances', $this->payload)
        ->assertAccepted()->assertJsonPath('data.kind', 'instance.create')->assertJsonPath('data.status', 'queued')->json('data.id');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()->assertJsonPath('data.id', $id);

    $instance = Instance::query()->sole();
    $route = $instance->routes()->sole();
    expect($instance->status->value)->toBe('reserved')->and($instance->first_setup_pending)->toBeTrue();
    expect($route->domain)->toBe('proof.admission.test')->and($route->publication->value)->toBe('private')
        ->and($route->status->value)->toBe('pending')->and($route->targets()->sole()->instance_id)->toBe($instance->id);
    expect(Operation::query()->sole()->input)->toMatchArray(['instance_id' => $instance->id, 'route_id' => $route->id,
        'route_domain' => $route->domain, 'checkout' => '/srv/apps/admission/proof', 'root' => null]);
});

it('refuses changed branch override root or layout on retry without changing reserved intent', function (string $change): void {
    $payload = [...$this->payload, 'branch' => 'release'];
    $this->postJson('/api/v1/instances', $payload)->assertAccepted();
    $instance = Instance::query()->sole();
    if ($change === 'layout') {
        $instance->update(['source_layout' => 'worktree']);
    } elseif ($change === 'root') {
        $payload['root'] = 'server/public';
    } elseif ($change === 'removed branch') {
        unset($payload['branch']);
    } else {
        $payload['branch'] = 'hotfix';
    }
    $before = $instance->getAttributes();

    $this->postJson('/api/v1/instances', $payload)->assertConflict()->assertJsonPath('error.code', 'instance.placement_conflict');
    expect($instance->refresh()->getAttributes())->toBe($before);
    expect(Operation::query()->count())->toBe(1)->and(Route::query()->count())->toBe(1);
})->with(['branch', 'removed branch', 'root', 'layout']);

it('refuses invalid or excluded placement before reserving an Instance', function (string $change, string $code): void {
    match ($change) {
        'node' => $this->node->update(['status' => 'failed']),
        'platform' => $this->node->update(['platform' => 'darwin']),
        'role' => $this->node->roles()->delete(),
        'production' => $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']),
        'excluded' => ProjectNodeExclusion::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id]),
    };

    $this->postJson('/api/v1/instances', $this->payload)->assertJsonPath('error.code', $code);
    expect(Instance::query()->count())->toBe(0)->and(Route::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
})->with([
    ['node', 'instance.node_inactive'], ['platform', 'instance.node_inactive'], ['role', 'instance.node_not_app_dev'],
    ['production', 'instance.candidate_required'], ['excluded', 'instance.node_excluded'],
]);

it('refuses occupied default checkout paths without reserving a second owner', function (bool $managed): void {
    if ($managed) {
        Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
            'name' => 'other', 'checkout_path' => '/srv/apps/admission/default', 'status' => 'active']);
    } else {
        $this->destinations->shouldReceive('assertUnoccupied')->once()
            ->andThrow(new ResourceOperationException('instance.migration_conflict', 'Occupied source.', 409));
    }

    $this->postJson('/api/v1/instances', [...$this->payload, 'name' => 'default'])->assertConflict()
        ->assertJsonPath('error.code', 'instance.default_path_occupied');
    expect(Instance::query()->where('name', 'default')->exists())->toBeFalse();
    expect(Route::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
})->with([true, false]);

it('keeps an admitted checkout immutable when the Node apps root changes', function (): void {
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();
    $first = Instance::query()->sole();
    $this->node->update(['settings' => ['apps' => ['path' => '/mnt/apps']]]);

    $this->postJson('/api/v1/instances', [...$this->payload, 'name' => 'later'])->assertAccepted();
    expect($first->refresh()->checkout_path)->toBe('/srv/apps/admission/proof');
    expect(Instance::query()->where('name', 'later')->sole()->checkout_path)->toBe('/mnt/apps/admission/later');
});
