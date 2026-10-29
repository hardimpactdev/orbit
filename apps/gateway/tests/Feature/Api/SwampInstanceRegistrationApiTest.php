<?php

declare(strict_types=1);

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
