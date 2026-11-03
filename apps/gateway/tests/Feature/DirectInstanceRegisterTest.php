<?php

declare(strict_types=1);

use App\Actions\Instances\RegisterInstanceAction;
use App\Actions\Routes\CreateRouteAction;
use App\Data\Instances\RegisterInstanceData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\InstanceRegisterResult;
use App\Domain\Instances\RegistrationFacts;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Route;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'Register proof', 'slug' => 'register-proof', 'repository_url' => 'https://github.com/example/proof.git', 'type' => 'node-package', 'root' => '.']);
    $this->node = Node::query()->create(['name' => 'register-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'tld' => 'test', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'ssh_host_fingerprint' => 'SHA256:register', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG', 'settings' => ['apps' => ['path' => '/srv/apps']]]);
    orbit_test_set_app_placement_role($this->node, false);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'proof', 'checkout_path' => '/srv/apps/register-proof/proof', 'source_layout' => 'checkout', 'status' => 'reserved']);
    $this->operation = Operation::query()->create(['status' => 'queued', 'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'kind' => 'instance.register', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'input' => ['checkout' => $this->instance->checkout_path, 'repository' => $this->project->repository_url, 'repository_identity' => $this->project->repository_identity, 'project_type' => 'node-package']]);
    $this->facts = RegistrationFacts::fromAgent(register_fact($this->instance->checkout_path), $this->instance->checkout_path, $this->project->repository_identity, $this->project->type, 'orbit');
    $this->operation->update(['input' => [...$this->operation->input, 'instance_id' => $this->instance->id, 'application' => $this->instance->applicationDirectory(), 'user' => 'orbit',
        'facts' => $this->facts, 'protect_environment' => true, 'run_setup' => false, 'setup' => null, 'members' => [], 'route_id' => null,
        'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($this->node)]]);
});

/** @param array<string,mixed> $change */
function register_fact(string $checkout, array $change = []): array
{
    return [...['owner' => 'orbit', 'top_level' => $checkout, 'common_dir' => $checkout.'/.git', 'linked' => false, 'worktrees' => [$checkout],
        'origin' => 'https://github.com/example/proof.git', 'branch' => 'feature', 'head' => str_repeat('a', 40), 'default_branch' => 'main',
        'application' => $checkout, 'artisan' => 'absent', 'composer' => null, 'env' => null], ...$change];
}

/**
 * The Node agent is online and reports one plain checkout of github.com/example/proof on branch feature.
 *
 * @param  array<string,mixed>  $change
 */
function register_report(Node $node, string $checkout, array $change = []): void
{
    app(CacheAgentStateView::class)->putSubscriber(true, true, 1);
    seed_agent_view($node->id, []);
    NodeFact::query()->updateOrCreate(['node_id' => $node->id, 'kind' => 'checkout', 'subject' => $checkout], [
        'digest' => bin2hex(random_bytes(32)), 'observed_at' => now(), 'received_at' => now(),
        'data' => register_fact($checkout, $change)]);
}

/** @param array<string,mixed> $result */
function register_terminal(string $status, array $result): array
{
    return ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => $status, 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => $result];
}

it('projects native adoption and source facts into the reserved Instance', function (): void {
    $operation = app(InstanceRegisterResult::class)->apply($this->operation, register_terminal('succeeded', ['steps' => ['Protect the .env' => ['status' => 'succeeded']]]));
    expect($operation->result)->toBe($this->facts);
    expect($this->instance->refresh()->source_is_laravel)->toBeFalse();
    expect($this->instance->status->value)->toBe('active')
        ->and($this->instance->branch)->toBe('feature')->and($this->instance->starting_commit)->toBe(str_repeat('a', 40))
        ->and($this->instance->registration_relocation_state)->toBe('relocated')->and($this->instance->registration_completed_at)->not->toBeNull();
});

it('keeps failed adoption as a failed Instance for a later retry', function (): void {
    $operation = app(InstanceRegisterResult::class)->apply($this->operation, [...register_terminal('failed', ['steps' => ['Protect the .env' => ['status' => 'failed']]]), 'failure' => ['failed_step' => 'Protect the .env']]);
    expect($operation->error_code)->toBe('instance.environment_protection_failed')
        ->and($this->instance->refresh()->status->value)->toBe('reserved')->and($this->instance->registration_completed_at)->toBeNull();
});

it('refuses unsupported registration before another row or Operation is reserved', function (array $change): void {
    $values = [...['sourcePath' => '/srv/apps/register-proof/other', 'includeWorktrees' => false, 'projectId' => $this->project->id, 'instanceName' => 'other', 'root' => null, 'domain' => null, 'runSetup' => false], ...$change];
    expect(fn () => app(RegisterInstanceAction::class)->execute($this->node, new RegisterInstanceData(...$values)))->toThrow(ResourceOperationException::class, 'Registration requires');
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
})->with([
    'worktrees' => [['includeWorktrees' => true]],
    'project inference' => [['projectId' => null]],
    'relocation' => [['sourcePath' => '/work/proof']],
]);

it('returns only recorded public intent to the native reader', function (): void {
    expect(app(InstanceRegisterResult::class)->input($this->operation)['intent']['checkout'])->toBe($this->instance->checkout_path)
        ->and(app(InstanceRegisterResult::class)->input($this->operation)['intent']['ssh_execution']['target']['host_keys'])->toBe([$this->node->ssh_host_key_type.' '.$this->node->ssh_host_key]);
});

$register = static fn (string $name = 'native'): Instance|Operation => app(RegisterInstanceAction::class)->execute(test()->node,
    new RegisterInstanceData('/srv/apps/register-proof/'.$name, false, test()->project->id, $name, null, null, false));

it('records a renamed linked worktree from the Project shared repository without a run', function () use ($register): void {
    register_report($this->node, '/srv/apps/register-proof/native', [
        'linked' => true, 'common_dir' => '/srv/apps/register-proof/default/.git',
        'worktrees' => ['/srv/apps/register-proof/default', '/srv/apps/register-proof/native'], 'branch' => 'renamed-feature',
    ]);

    $instance = $register();

    expect($instance)->toBeInstanceOf(Instance::class)
        ->and($instance->source_layout)->toBe('worktree')
        ->and($instance->branch)->toBe('renamed-feature')
        ->and($instance->registration_common_repository_path)->toBe('/srv/apps/register-proof/default/.git')
        ->and(Operation::query()->count())->toBe(1);
});

it('admits Project setup for a reported checkout without reading the Node', function (): void {
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'native-register-fixture']);
    register_report($this->node, '/srv/apps/register-proof/native');

    ProjectLifecycleStep::query()->create(['project_id' => $this->project->id, 'phase' => 'setup', 'position' => 0,
        'name' => 'prepare', 'command' => 'touch .env', 'timeout_seconds' => 20]);
    $operation = app(RegisterInstanceAction::class)->execute($this->node,
        new RegisterInstanceData('/srv/apps/register-proof/native', false, $this->project->id, 'native', null, null, true));

    expect($operation)->toBeInstanceOf(Operation::class)
        ->and($operation->input['facts']['commit'])->toBe(str_repeat('a', 40))
        ->and($operation->input['run_setup'])->toBeTrue()
        ->and($operation->input['protect_environment'])->toBeFalse()
        ->and(app(InstanceRegisterResult::class)->input($operation)['intent'])->toHaveKeys(['setup_steps', 'processes']);
});

it('records a checkout with a private or absent .env at once, with no task', function (?array $env) use ($register): void {
    register_report($this->node, '/srv/apps/register-proof/native', ['env' => $env]);

    $instance = $register();

    expect($instance)->toBeInstanceOf(Instance::class)->and(Operation::query()->count())->toBe(1)
        ->and($instance->status->value)->toBe('active')->and($instance->branch)->toBe('feature')
        ->and($instance->starting_commit)->toBe(str_repeat('a', 40))->and($instance->registration_default_branch)->toBe('main')
        ->and($instance->registration_source_digest)->toBeNull()->and($instance->registration_completed_at)->not->toBeNull();
})->with([
    'absent .env' => [null],
    'owner-only .env' => [['kind' => 'regular', 'mode' => '0600', 'owner' => 'orbit']],
]);

it('starts the run that protects .env when others can read it', function (Closure $change) use ($register): void {
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'native-register-fixture']);
    $change->call($this);

    expect($register())->toBeInstanceOf(Operation::class);
    expect(Instance::query()->where('name', 'native')->sole()->status->value)->toBe('reserved');
})->with([
    'group-readable .env' => [fn () => register_report($this->node, '/srv/apps/register-proof/native', ['env' => ['kind' => 'regular', 'mode' => '0640', 'owner' => 'orbit']])],
]);

it("can't verify a checkout the agent has not reported, and reserves nothing", function () use ($register): void {
    app(CacheAgentStateView::class)->putSubscriber(true, true, 1);
    seed_agent_view($this->node->id, []);

    expect(fn () => $register())->toThrow(ResourceOperationException::class, "can't verify");
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
});

it('refuses a reported checkout that is not one plain checkout of the Project by the Node account', function (array $change, string $code) use ($register): void {
    register_report($this->node, '/srv/apps/register-proof/native', $change);

    try {
        $register();
        $this->fail('Registration should refuse.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe($code);
    }
    expect(Instance::query()->count())->toBe(1);
})->with([
    'linked worktree' => [['linked' => true], 'instance.registration_conflict'],
    'missing from worktree list' => [['worktrees' => ['/srv/apps/register-proof/other']], 'instance.registration_conflict'],
    'another repository' => [['origin' => 'https://github.com/example/other.git'], 'instance.source_identity_invalid'],
    'masked origin' => [['origin' => 'https://REDACTED@github.com/example/proof.git'], 'instance.source_identity_invalid'],
    'another owner' => [['owner' => 'root'], 'instance.source_owner_invalid'],
    'no commit' => [['head' => null], 'instance.source_invalid'],
]);

it('admits and completes in-place registration through one real native HTTP root', function (): void {
    register_report($this->node, '/srv/apps/register-proof/native', ['env' => ['kind' => 'regular', 'mode' => '0644', 'owner' => 'orbit']]);
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'register-gateway', 'status' => 'active',
        'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node->accessibleNodes()->attach($gateway);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $agent = Mockery::mock(AgentCommandRunner::class);
    $agent->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agent);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'native-register-fixture']);
    $body = ['project_id' => $this->project->id, 'source_path' => '/srv/apps/register-proof/native', 'instance_name' => 'native'];
    $response = $this->withHeader('Idempotency-Key', 'register.native.fixture')->postJson('/api/v1/instances/register', $body)->assertAccepted();
    $id = $response->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    $instance = Instance::query()->findOrFail($operation->target_id);
    expect($instance->status->value)->toBe('reserved')->and($instance->branch)->toBe('feature');
    app(StartSwampRun::class)->start($operation);
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-instance-register');
    expect($socket->sent[0]['payload']['inputs'])->toMatchArray(['operation_id' => $id, 'kind' => 'instance.register', 'node_id' => $this->node->id])
        ->and($socket->sent[0]['payload']['inputs']['intent']['checkout'])->toBe($operation->input['checkout']);
    $this->postJson('/api/v1/instances/register', $body)->assertAccepted()->assertJsonPath('data.id', $id);
    $this->withoutHeader('Idempotency-Key');
    $history = ['id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'workflowName' => 'operation-instance-register',
        'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id],
        'status' => 'succeeded', 'startedAt' => now()->format('Y-m-d\TH:i:s.v\Z'), 'completedAt' => now()->format('Y-m-d\TH:i:s.v\Z'),
        'jobs' => [['name' => 'register', 'status' => 'succeeded', 'steps' => [
            ['name' => 'Protect the .env', 'status' => 'succeeded'], ['name' => 'Create the Route', 'status' => 'skipped'],
        ]]]];
    config(['swamp.read_token' => 'fixture.read']);
    $socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $history]]];
    $selector = app(DirectOperation::class)->definition('instance.register')['terminal_result'];
    app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $history['id'], 'status' => 'succeeded'], $selector);
    app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $history['id'], 'status' => 'succeeded'], $selector);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run', 'workflow.history.get']);
    expect($operation->refresh()->lock_key)->toBeNull();
    expect($instance->refresh()->source_is_laravel)->toBeFalse();
    expect(app(InstanceEnvironmentContextResolver::class)->resolve($instance, true)->laravel)->toBeFalse();
    expect($instance->status->value)->toBe('active')->and($instance->branch)->toBe('feature');
    expect($instance->registration_completed_at)->not->toBeNull();
    expect($operation->input)->not->toHaveKey('agent_sha256');
});

it('requires a native result for every requested effect', function (): void {
    expect(fn () => app(InstanceRegisterResult::class)->apply($this->operation, register_terminal('succeeded', ['steps' => []])))
        ->toThrow(ResourceOperationException::class, 'Registration has no successful result');
});

it('reserves a Route for a Laravel registration without replacing its user environment', function (): void {
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'native-register-fixture']);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-public-root');
    $this->project->update(['type' => 'laravel-app', 'root' => 'public']);
    register_report($this->node, '/srv/apps/register-proof/native', ['artisan' => 'regular',
        'composer' => json_encode(['require' => ['php' => '^8.5', 'laravel/framework' => '^13.0']])]);

    $operation = app(RegisterInstanceAction::class)->execute($this->node,
        new RegisterInstanceData('/srv/apps/register-proof/native', false, $this->project->id, 'native', null, 'native.register-proof.test', true));

    expect($operation->input['route_id'])->toBeInt()->and($operation->input['route_create']['env_files'])->toBe([])
        ->and(Route::query()->findOrFail($operation->input['route_id'])->domain)->toBe('native.register-proof.test');
    $instance = Instance::query()->where('name', 'native')->sole();
    expect($instance->source_is_laravel)->toBeTrue()->and($instance->status->value)->toBe('reserved');
    expect($operation->input['route_create']['caddy'])->toHaveCount(1)
        ->and($operation->input['route_create']['caddy'][0]['content'])->toContain('native.register-proof.test')
        ->and($operation->input['route_create']['php_files'][0]['content'])->toContain('/srv/apps/register-proof/native')
        ->and(array_column($operation->input['route_create']['probes'], 'domain'))->toContain('native.register-proof.test');
    expect($operation->input['route_create']['awake'])->toBe([['node_id' => $this->node->id, 'instance_id' => $instance->id,
        'path' => '/dev/shm/orbit/hibernation/app-instance-'.$instance->id.'.awake']]);
});

it('records setup with no steps or Processes without an empty run', function (): void {
    register_report($this->node, '/srv/apps/register-proof/native');
    $instance = app(RegisterInstanceAction::class)->execute($this->node,
        new RegisterInstanceData('/srv/apps/register-proof/native', false, $this->project->id, 'native', null, null, true));
    expect($instance)->toBeInstanceOf(Instance::class)->and($instance->status->value)->toBe('active')
        ->and($instance->first_setup_pending)->toBeFalse()->and(Operation::query()->count())->toBe(1);
});

it('reuses only its own reserved Route when a registration is retried', function (): void {
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'native-register-fixture']);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-public-root');
    $this->project->update(['type' => 'laravel-app', 'root' => 'public']);
    register_report($this->node, '/srv/apps/register-proof/native');
    $data = new RegisterInstanceData('/srv/apps/register-proof/native', false, $this->project->id, 'native', null, 'native.register-proof.test', false);
    $first = app(RegisterInstanceAction::class)->execute($this->node, $data);
    app(InstanceRegisterResult::class)->apply($first, register_terminal('failed', ['steps' => ['Create the Route' => ['status' => 'failed']]]));
    $retry = app(RegisterInstanceAction::class)->execute($this->node, $data);
    expect($retry->id)->not->toBe($first->id)->and($retry->target_id)->toBe($first->target_id)
        ->and($retry->input['route_id'])->toBe($first->input['route_id'])->and(Route::query()->count())->toBe(1);
    app(InstanceRegisterResult::class)->apply($retry, register_terminal('succeeded', ['steps' => ['Create the Route' => ['status' => 'succeeded']]]));
    expect(Instance::query()->findOrFail($retry->target_id)->status->value)->toBe('active')
        ->and(Route::query()->findOrFail($retry->input['route_id'])->status->value)->toBe('active');
});

it('refuses application facts for a different requested root', function (): void {
    register_report($this->node, '/srv/apps/register-proof/native', ['application' => '/srv/apps/register-proof/native/other']);
    expect(fn () => app(RegisterInstanceAction::class)->execute($this->node,
        new RegisterInstanceData('/srv/apps/register-proof/native', false, $this->project->id, 'native', null, null, false)))
        ->toThrow(ResourceOperationException::class, 'requested application directory');
    expect(Instance::query()->count())->toBe(1);
});

it('returns the Instance with HTTP 201 and no Operation for plain registration', function (string $type): void {
    $this->project->update(['type' => $type, 'root' => $type === 'laravel-app' ? 'public' : '.']);
    register_report($this->node, '/srv/apps/register-proof/native');
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'register-gateway', 'status' => 'active',
        'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node->accessibleNodes()->attach($gateway);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->postJson('/api/v1/instances/register', ['source_path' => '/srv/apps/register-proof/native', 'project_id' => $this->project->id])
        ->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.name', 'native');
    expect(Operation::query()->count())->toBe(1);
})->with(['node-package', 'laravel-app']);

it('refuses a domain owned by another Instance before reserving a registration', function (): void {
    $this->project->update(['type' => 'laravel-app', 'root' => 'public']);
    app(CreateRouteAction::class)->ensureForInstance($this->instance, 'taken.register-proof.test');
    register_report($this->node, '/srv/apps/register-proof/native');
    try {
        app(RegisterInstanceAction::class)->execute($this->node,
            new RegisterInstanceData('/srv/apps/register-proof/native', false, $this->project->id, 'native', null, 'taken.register-proof.test', false));
        $this->fail('The domain must be refused.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('route.domain_conflict')->and($exception->status)->toBe(422);
    }
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
});

it('keeps completed development Processes when a registration Route fails', function (): void {
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'native-register-fixture']);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-public-root');
    $this->project->update(['type' => 'laravel-app', 'root' => 'public']);
    $definition = $this->project->processDefinitions()->create(['name' => 'dev-server', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/sleep', '300'], 'restart_policy' => 'never']]);
    register_report($this->node, '/srv/apps/register-proof/native');
    $data = new RegisterInstanceData('/srv/apps/register-proof/native', false, $this->project->id, 'native', null, 'native.register-proof.test', true);
    $operation = app(RegisterInstanceAction::class)->execute($this->node, $data);
    $intent = app(InstanceRegisterResult::class)->input($operation)['intent'];
    expect($intent['processes'])->toHaveCount(1)->and($intent['setup_steps'])->toBe([]);
    app(InstanceRegisterResult::class)->apply($operation, [
        ...register_terminal('failed', ['steps' => ['Create, start Processes-0' => ['status' => 'succeeded'], 'Create the Route' => ['status' => 'failed']]]),
        'failure' => ['failed_step' => 'Create the Route'],
    ]);
    $process = Process::query()->where('source_definition_id', $definition->id)->sole();
    expect($process->status->value)->toBe('active')->and($process->desired_state->value)->toBe('running')
        ->and(Instance::query()->findOrFail($operation->target_id)->status->value)->toBe('reserved');
    $retry = app(RegisterInstanceAction::class)->execute($this->node, $data);
    expect(array_column($retry->input['members'], 'id'))->toBe([$process->id])->and(Process::query()->count())->toBe(1);
    app(InstanceRegisterResult::class)->apply($retry, register_terminal('succeeded', ['steps' => [
        'Create, start Processes-0' => ['status' => 'succeeded'], 'Create the Route' => ['status' => 'succeeded'],
    ]]));
    expect(Instance::query()->findOrFail($retry->target_id)->status->value)->toBe('active')
        ->and($process->refresh()->status->value)->toBe('active');
});

it('requires existing Processes to be checked before waking a completed registration on retry', function (bool $setup): void {
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'native-register-fixture']);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-public-root');
    $this->project->update(['type' => 'laravel-app', 'root' => 'public']);
    $this->project->processDefinitions()->create(['name' => 'dev-server', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/sleep', '300'], 'restart_policy' => 'never']]);
    register_report($this->node, '/srv/apps/register-proof/native');
    $first = app(RegisterInstanceAction::class)->execute($this->node,
        new RegisterInstanceData('/srv/apps/register-proof/native', false, $this->project->id, 'native', null, 'native.register-proof.test', true));
    app(InstanceRegisterResult::class)->apply($first, register_terminal('succeeded', ['steps' => [
        'Create, start Processes-0' => ['status' => 'succeeded'], 'Create the Route' => ['status' => 'succeeded'],
    ]]));
    $retry = app(RegisterInstanceAction::class)->execute($this->node,
        new RegisterInstanceData('/srv/apps/register-proof/native', false, $this->project->id, 'native', null, 'native.register-proof.test', $setup));

    expect($retry->input['members'])->toHaveCount($setup ? 1 : 0)
        ->and($retry->input['route_create']['awake'])->toHaveCount($setup ? 1 : 0);
})->with([false, true]);
