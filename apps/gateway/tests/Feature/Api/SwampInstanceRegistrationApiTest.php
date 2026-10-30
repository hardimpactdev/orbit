<?php

declare(strict_types=1);

use App\Actions\Routes\CreateRouteAction;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\Registration\RegistrationSourceFacts;
use App\Domain\Instances\Registration\RegistrationSourceManager;
use App\Domain\Instances\RegistrationLifecycleExecution;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), [
        ...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
    ]));
    $this->node = Node::query()->create(['name' => 'registration-dev', 'user' => 'orbit', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'status' => 'active', 'tld' => 'test',
        'settings' => ['apps' => ['path' => '/srv/apps']]]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->node->accessibleNodes()->attach($this->node);
    $this->project = Project::query()->create(['name' => 'Registration', 'slug' => 'registration', 'type' => 'laravel-app',
        'root' => 'public', 'repository_url' => 'https://github.com/example/registration.git', 'default_branch' => 'main']);
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
    $this->source = Mockery::mock(RegistrationSourceManager::class);
    $this->source->shouldNotReceive('relocate', 'relocateSet', 'restoreOriginal', 'prepareLaravelRollback', 'restoreLaravelConfiguration', 'discardLaravelRollback');
    app()->instance(RegistrationSourceManager::class, $this->source);
    $this->configuration = Mockery::mock(DevelopmentInstanceConfigurator::class);
    $this->configuration->shouldNotReceive('configureLaravelUrl');
    app()->instance(DevelopmentInstanceConfigurator::class, $this->configuration);
    $destinations = Mockery::mock(InstanceDestinationGuard::class);
    $destinations->shouldReceive('assertUnoccupied')->andReturnNull();
    app()->instance(InstanceDestinationGuard::class, $destinations);
    Queue::fake();
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
});

afterEach(function (): void {
    Queue::assertNothingPushed();
});

it('reserves adopted source and Route intent and replays admission without executing relocation or setup', function (bool $setup): void {
    $this->source->shouldReceive('inspect')->once()->withArgs(fn (Node $node, string $path, bool $trees): bool => $node->id === $this->node->id && $path === '/work/registration' && ! $trees)
        ->andReturn([swamp_registration_facts()]);
    $this->configuration->shouldReceive('inspect')->once()->andReturn(new DevelopmentSourceProfile('8.5', true));
    $this->withHeader('Idempotency-Key', 'register-admission');
    $body = ['source_path' => '/work/registration', 'setup' => $setup];

    $id = $this->postJson('/api/v1/instances/register', $body)->assertAccepted()->assertJsonPath('data.kind', 'instance.register')
        ->assertJsonPath('data.status', 'queued')->json('data.id');
    $this->postJson('/api/v1/instances/register', $body)->assertAccepted()->assertJsonPath('data.id', $id);

    $instance = Instance::query()->sole();
    $operation = Operation::query()->sole();
    expect($instance->name)->toBe('default')->and($instance->checkout_path)->toBe('/srv/apps/registration/default')
        ->and($instance->registration_original_path)->toBe('/work/registration')->and($instance->registration_completed_at)->toBeNull()
        ->and($instance->status->value)->toBe('reserved')->and($instance->starting_commit)->toBe(str_repeat('a', 40));
    expect($operation->target_id)->toBe($instance->id)->and($operation->node_id)->toBe($this->node->id)
        ->and($operation->input['run_setup'])->toBe($setup)->and($operation->input['setup_digest'])->toBe(hash('sha256', '[]'));
    expect($operation->input['members'][0])->toMatchArray(['instance_id' => $instance->id,
        'original_path' => '/work/registration', 'source_digest' => str_repeat('c', 64), 'completed' => false]);
    $route = $instance->routes()->sole();
    expect($route->domain)->toBe('registration.test')->and($route->status->value)->toBe('pending');
    expect(json_encode(Activity::query()->where('command', 'instance:register')->firstOrFail()->toArray()))->not->toContain('/work/registration');
})->with([false, true]);

it('adopts the current worktree of a resolved ended create without reserving another Instance', function (string $createStatus): void {
    $path = '/srv/apps/registration/feature';
    $instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'feature', 'source_layout' => 'worktree', 'checkout_path' => $path, 'branch' => 'feature',
        'starting_commit' => str_repeat('b', 40), 'source_prepare_id' => 'prepared-create', 'status' => 'source_resolved',
        'first_setup_pending' => true]);
    $route = app(CreateRouteAction::class)->ensureForInstance($instance, null);
    Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $this->node->id, 'status' => $createStatus, 'input' => ['instance_id' => $instance->id]]);
    $this->source->shouldReceive('inspect')->once()->andReturn([swamp_registration_facts($path, 'feature-work', InstanceSourceLayout::Worktree)]);
    $this->configuration->shouldReceive('inspect')->once()->andReturn(new DevelopmentSourceProfile('8.5', true));

    $id = $this->postJson('/api/v1/instances/register', ['source_path' => $path, 'project_id' => $this->project->id,
        'instance_name' => 'feature', 'setup' => true])->assertAccepted()->assertJsonPath('data.target_id', $instance->id)->json('data.id');

    expect(Instance::query()->count())->toBe(1);
    expect($instance->refresh()->branch)->toBe('feature-work')->and($instance->branch_override)->toBe('feature-work')
        ->and($instance->starting_commit)->toBe(str_repeat('a', 40))->and($instance->source_prepare_id)->toBe('prepared-create')
        ->and($instance->registration_original_path)->toBe($path)->and($instance->first_setup_pending)->toBeTrue();
    $operation = Operation::query()->findOrFail($id);
    expect($operation->input['members'][0])->toMatchArray(['instance_id' => $instance->id, 'original_path' => $path,
        'checkout' => $path, 'branch' => 'feature-work', 'commit' => str_repeat('a', 40)]);
    $descriptor = app(RegistrationLifecycleExecution::class)->descriptors($operation)[0];
    expect($descriptor['input']['operation'])->toBe('adopt');
    expect($instance->routes()->sole()->id)->toBe($route->id);
})->with(['failed', 'cancelled']);

it('refuses adoption outside resolved source from an ended create without changing the Instance', function (string $status, ?string $createStatus, string $requestedName): void {
    $path = '/srv/apps/registration/feature';
    $instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'feature', 'source_layout' => 'worktree', 'checkout_path' => $path, 'branch' => 'feature',
        'starting_commit' => str_repeat('b', 40), 'source_prepare_id' => 'prepared-create', 'status' => $status]);
    if ($createStatus !== null) {
        Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $instance->id,
            'node_id' => $this->node->id, 'status' => $createStatus, 'input' => ['instance_id' => $instance->id]]);
    }
    $this->source->shouldReceive('inspect')->once()->andReturn([swamp_registration_facts($path, 'feature-work', InstanceSourceLayout::Worktree)]);
    $this->configuration->shouldReceive('inspect')->once()->andReturn(new DevelopmentSourceProfile('8.5', true));

    $this->postJson('/api/v1/instances/register', ['source_path' => $path, 'instance_name' => $requestedName])
        ->assertConflict()->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($instance->refresh()->branch)->toBe('feature')->and($instance->starting_commit)->toBe(str_repeat('b', 40))
        ->and($instance->registration_request_id)->toBeNull()->and($instance->status->value)->toBe($status);
    expect(Instance::query()->count())->toBe(1)->and(Route::query()->count())->toBe(0)
        ->and(Operation::query()->where('kind', 'instance.register')->count())->toBe(0);
})->with([
    'active Instance' => ['active', 'cancelled', 'feature'],
    'unresolved source' => ['reserved', 'cancelled', 'feature'],
    'no ended create' => ['source_resolved', null, 'feature'],
    'running create' => ['source_resolved', 'running', 'feature'],
    'changed name' => ['source_resolved', 'failed', 'renamed'],
]);

it('refuses a cancelled create checkout while another create owns its Instance or path', function (string $status, bool $sameTarget): void {
    $path = '/srv/apps/registration/feature';
    $instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'feature', 'source_layout' => 'worktree', 'checkout_path' => $path, 'branch' => 'feature',
        'starting_commit' => str_repeat('b', 40), 'status' => 'source_resolved']);
    app(CreateRouteAction::class)->ensureForInstance($instance, null);
    Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $this->node->id, 'status' => 'cancelled', 'input' => ['instance_id' => $instance->id]]);
    Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance',
        'target_id' => $sameTarget ? $instance->id : $instance->id + 1000, 'node_id' => $this->node->id,
        'status' => $status, 'input' => ['checkout' => $sameTarget ? '/another/path' : $path]]);
    $this->source->shouldReceive('inspect')->once()->andReturn([swamp_registration_facts($path, 'feature-work', InstanceSourceLayout::Worktree)]);
    $this->configuration->shouldReceive('inspect')->once()->andReturn(new DevelopmentSourceProfile('8.5', true));

    $this->postJson('/api/v1/instances/register', ['source_path' => $path, 'instance_name' => 'feature'])
        ->assertConflict()->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($instance->refresh()->branch)->toBe('feature')->and($instance->starting_commit)->toBe(str_repeat('b', 40))
        ->and($instance->registration_request_id)->toBeNull();
    expect(Operation::query()->where('kind', 'instance.register')->count())->toBe(0);
})->with([
    'queued same Instance' => ['queued', true], 'running same Instance' => ['running', true],
    'waiting same Instance' => ['waiting', true], 'queued same path' => ['queued', false],
    'running same path' => ['running', false], 'waiting same path' => ['waiting', false],
]);

it('refuses ambiguous source path matches without selecting an Instance', function (): void {
    $path = '/srv/apps/registration/feature';
    $instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'feature', 'source_layout' => 'worktree', 'checkout_path' => $path, 'branch' => 'feature',
        'status' => 'source_resolved']);
    app(CreateRouteAction::class)->ensureForInstance($instance, null);
    Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'other', 'checkout_path' => '/srv/apps/registration/other', 'status' => 'reserved',
        'registration_original_path' => $path, 'registration_authoritative_path' => $path,
        'registration_relocation_state' => 'reserved']);
    Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $this->node->id, 'status' => 'cancelled', 'input' => ['instance_id' => $instance->id]]);
    $this->source->shouldReceive('inspect')->once()->andReturn([swamp_registration_facts($path, 'feature-work', InstanceSourceLayout::Worktree)]);
    $this->configuration->shouldReceive('inspect')->once()->andReturn(new DevelopmentSourceProfile('8.5', true));

    $this->postJson('/api/v1/instances/register', ['source_path' => $path])->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_evidence_invalid');

    expect($instance->refresh()->branch)->toBe('feature')->and($instance->registration_request_id)->toBeNull();
    expect(Operation::query()->where('kind', 'instance.register')->count())->toBe(0);
});

it('refuses to adopt a checkout owned by another Project', function (): void {
    $path = '/srv/apps/registration/feature';
    $other = Project::query()->create(['name' => 'Other', 'slug' => 'other', 'type' => 'laravel-app', 'root' => 'public',
        'repository_url' => 'https://github.com/example/other.git', 'default_branch' => 'main']);
    $instance = Instance::query()->create(['project_id' => $other->id, 'node_id' => $this->node->id,
        'name' => 'feature', 'source_layout' => 'worktree', 'checkout_path' => $path, 'branch' => 'feature',
        'starting_commit' => str_repeat('b', 40), 'status' => 'source_resolved']);
    Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $this->node->id, 'status' => 'cancelled', 'input' => ['instance_id' => $instance->id]]);
    $this->source->shouldReceive('inspect')->once()->andReturn([swamp_registration_facts($path, 'feature-work', InstanceSourceLayout::Worktree)]);
    $this->configuration->shouldReceive('inspect')->once()->andReturn(new DevelopmentSourceProfile('8.5', true));

    $this->postJson('/api/v1/instances/register', ['source_path' => $path, 'project_id' => $this->project->id])
        ->assertConflict()->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($instance->refresh()->project_id)->toBe($other->id)->and($instance->branch)->toBe('feature')
        ->and($instance->registration_request_id)->toBeNull();
    expect(Instance::query()->count())->toBe(1)->and(Route::query()->count())->toBe(0)
        ->and(Operation::query()->where('kind', 'instance.register')->count())->toBe(0);
});

it('preflights every adopted worktree before reserving the bounded member set', function (): void {
    $facts = [swamp_registration_facts(), swamp_registration_facts('/work/feature', 'feature', InstanceSourceLayout::Worktree)];
    $this->source->shouldReceive('inspect')->once()->andReturn($facts);
    $this->configuration->shouldReceive('inspect')->twice()->andReturn(new DevelopmentSourceProfile('8.5', true));

    $this->postJson('/api/v1/instances/register', ['source_path' => '/work/registration', 'include_worktrees' => true])->assertAccepted();
    $operation = Operation::query()->sole();
    expect(array_column($operation->input['members'], 'original_path'))->toBe(['/work/registration', '/work/feature']);
    expect(Instance::query()->orderBy('id')->pluck('name')->all())->toBe(['default', 'feature']);
    expect(Route::query()->count())->toBe(2)->and(Instance::query()->whereNotNull('registration_completed_at')->count())->toBe(0);
});

it('refuses unsafe source metadata before reserving any member', function (): void {
    $this->source->shouldReceive('inspect')->once()->andReturn([swamp_registration_facts()]);
    $this->configuration->shouldReceive('inspect')->once()->andThrow(new ResourceOperationException('app-dev.source_metadata_unsafe', 'Unsafe source.', 409));

    $this->postJson('/api/v1/instances/register', ['source_path' => '/work/registration'])->assertConflict()
        ->assertJsonPath('error.code', 'app-dev.source_metadata_unsafe');
    expect(Instance::query()->count())->toBe(0)->and(Route::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
});

it('refuses an unowned repository instead of creating a Project', function (): void {
    $this->project->delete();
    $this->source->shouldReceive('inspect')->once()->andReturn([swamp_registration_facts()]);

    $this->postJson('/api/v1/instances/register', ['source_path' => '/work/registration'])->assertJsonPath('error.code', 'instance.project_missing');
    expect(Project::query()->count())->toBe(0)->and(Instance::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
});

it('admits registration setup using the parent digest after its primary member activates', function (): void {
    $this->source->shouldReceive('inspect')->once()->andReturn([swamp_registration_facts()]);
    $this->configuration->shouldReceive('inspect')->once()->andReturn(new DevelopmentSourceProfile('8.5', true));
    $this->postJson('/api/v1/instances/register', ['source_path' => '/work/registration', 'setup' => true])->assertAccepted();
    $parent = Operation::query()->sole();
    $gateway = Node::query()->create(['name' => 'registration-gateway', 'user' => 'orbit', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'status' => 'active']);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $executor = Node::query()->create(['name' => 'registration-executor', 'user' => 'orbit', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.17', 'wireguard_ip' => '10.44.0.17', 'status' => 'active']);
    $executor->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $executor->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $executor->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.register', 'worker' => 'register-worker',
        'operation_id' => $parent->id])->assertOk()->assertJsonPath('data.id', $parent->id);
    $parent->refresh();
    $instance = Instance::query()->sole();
    $instance->routes()->sole()->update(['status' => 'active']);
    $instance->update(['status' => 'active', 'provisioning_step' => 'active', 'first_setup_pending' => true]);
    $key = "registration-{$instance->id}-activate";
    $descriptor = array_find(app(RegistrationLifecycleExecution::class)->descriptors($parent), static fn (array $entry): bool => $entry['key'] === $key);
    $parent->update(['state' => [...($parent->state ?? []), 'lifecycle_bindings' => [$key => $descriptor],
        'executor_runs' => [$key => [...$descriptor, 'result' => ['activated' => true, 'instance_id' => $instance->id]]]]]);
    $parent->steps()->create(['name' => $key, 'position' => 1, 'sequence' => 1, 'status' => 'succeeded', 'resumable' => true]);

    config(['swamp.run_token' => 'fixture.shared.token']);
    $socket = new \Tests\Support\FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-instance-setup-direct', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(\App\Infrastructure\AgentView\WebSocketClient::class, $socket);
    $path = '/api/v1/operation-executor/'.$parent->id.'/children';
    $response = $this->postJson($path, ['worker' => 'register-worker', 'key' => 'setup']);
    $id = $response->assertAccepted()
        ->assertJsonPath('data.kind', 'instance.run_steps')->assertJsonPath('data.parent_operation_id', $parent->id)
        ->assertJsonPath('data.input', ['instance_id' => $instance->id, 'source' => 'project', 'list' => 'setup',
            'configuration_digest' => $parent->input['setup_digest'], ...app(\App\Actions\Instances\StartInstanceStepListAction::class)->appStepAdmission($instance, \App\Domain\Projects\LifecyclePhase::Setup)])->json('data.id');

    $this->postJson($path, ['worker' => 'register-worker', 'key' => 'setup'])->assertOk()->assertJsonPath('data.id', $id);
    expect(Operation::query()->where('kind', 'instance.run_steps')->count())->toBe(1);
});

it('requires proven Laravel restoration after a confirmed provisioning refusal and forbids early receipt discard', function (): void {
    $this->source->shouldReceive('inspect')->once()->andReturn([swamp_registration_facts()]);
    $this->configuration->shouldReceive('inspect')->once()->andReturn(new DevelopmentSourceProfile('8.5', true));
    $this->postJson('/api/v1/instances/register', ['source_path' => '/work/registration'])->assertAccepted();
    $operation = Operation::query()->sole();
    $id = $operation->target_id;
    $execution = app(RegistrationLifecycleExecution::class);
    $proof = function (string $key, array $result, bool $success = true) use ($operation, $execution): void {
        $descriptor = array_find($execution->descriptors($operation), static fn (array $descriptor): bool => $descriptor['key'] === $key);
        $operation->update(['state' => [...($operation->state ?? []),
            'lifecycle_bindings' => [...($operation->state['lifecycle_bindings'] ?? []), $key => $descriptor],
            'executor_runs' => [...($operation->state['executor_runs'] ?? []), $key => [...$descriptor, 'result' => $result]]]]);
        $operation->steps()->create(['name' => $key, 'position' => $operation->steps()->count(), 'sequence' => 1,
            'status' => $success ? 'succeeded' : 'failed', 'resumable' => true]);
    };
    $run = static fn (string $key): array => ['worker' => 'registration-worker', 'key' => $key, 'transport' => 'instance_lifecycle',
        'name' => 'instance.laravel-registration-receipt', 'input' => []];
    $proof('registration-relocate-cleanup', ['relocated' => true]);
    $proof("registration-{$id}-receipt-prepare", []);
    $proof("registration-{$id}-profile", ['error_code' => 'app-dev.php_version_unsupported'], false);

    expect($execution->canRestore($operation, $id))->toBeTrue();
    expect(fn () => $execution->beforeRun($operation, $run("registration-{$id}-receipt-discard")))->toThrow(ResourceOperationException::class);
    expect(fn () => $execution->assertFailure($operation, OperationOutcome::failed('app-dev.php_version_unsupported')))->toThrow(ResourceOperationException::class);
    $execution->beforeRun($operation, $run("registration-{$id}-receipt-restore"));
    $proof("registration-{$id}-receipt-restore", []);
    $execution->assertFailure($operation, OperationOutcome::failed('app-dev.php_version_unsupported'));
    $operation->update(['cancel_requested_at' => now()]);
    expect($execution->canRestore($operation, $id))->toBeFalse();
    expect(fn () => $execution->beforeRun($operation, $run("registration-{$id}-receipt-restore")))->toThrow(ResourceOperationException::class);
    expect(Instance::query()->sole()->registration_completed_at)->toBeNull();
});

function swamp_registration_facts(string $path = '/work/registration', string $branch = 'main', InstanceSourceLayout $layout = InstanceSourceLayout::Checkout): RegistrationSourceFacts
{
    return new RegistrationSourceFacts(path: $path, layout: $layout, repositoryUrl: 'git@github.com:example/registration.git',
        repositoryIdentity: 'github.com/example/registration', branch: $branch, detached: false, commit: str_repeat('a', 40),
        defaultBranch: 'main', inferredSlug: 'registration', inferredRoot: 'public', commonRepositoryPath: '/work/registration/.git',
        worktreePaths: ['/work/registration', '/work/feature'], sourceDigest: str_repeat('c', 64));
}
