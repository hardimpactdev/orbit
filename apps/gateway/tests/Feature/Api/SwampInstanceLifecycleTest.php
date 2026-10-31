<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceStepListAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\GitHub\GitHubCliToken;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceLifecycleCommand;
use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationGatewayCommands;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), [
        ...OperationKinds::definitions(),
        ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
    ]));
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $node = static fn (string $name, string $ip): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit',
        'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => $ip,
    ]);
    $this->gateway = $node('gateway', '10.44.0.1');
    NodeRole::query()->create(['node_id' => $this->gateway->id, 'role' => RoleName::Gateway, 'status' => 'active']);
    $this->dev = $node('dev', '10.44.0.9');
    NodeRole::query()->create(['node_id' => $this->dev->id, 'role' => RoleName::AppDev, 'status' => 'active']);
    $this->dev->accessibleNodes()->attach($this->dev);
    $this->services = $node('services', '10.44.0.17');
    $this->services->accessibleNodes()->attach($this->gateway);
    config(['dependency_detection.services_node_id' => $this->services->id]);
    $project = Project::query()->create(['name' => 'Lifecycle', 'slug' => 'lifecycle',
        'repository_url' => 'https://github.com/example/lifecycle.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->dev->id,
        'name' => 'proof', 'checkout_path' => '/srv/apps/lifecycle/proof', 'source_layout' => 'checkout',
        'status' => InstanceState::Active, 'provisioning_step' => 'active', 'branch' => 'old']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->dev->wireguard_ip]);
    Queue::fake();
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.direct_writers' => ['instance.rename' => 'direct']]);
    $response = $this->withHeader('Idempotency-Key', 'rename-proof')
        ->postJson('/api/v1/instances/'.$this->instance->id.'/rename', ['branch' => 'feature'])
        ->assertAccepted();
    $this->operation = Operation::query()->findOrFail($response->json('data.id'));
    $this->path = '/api/v1/operation-executor/'.$this->operation->id;
    $this->withoutHeader('Idempotency-Key')->withServerVariables(['REMOTE_ADDR' => $this->services->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.rename', 'worker' => 'proof-worker'])
        ->assertOk()->assertJsonPath('data.id', $this->operation->id);
    $descriptor = app(InstanceLifecycleExecution::class)->context($this->operation->refresh())['descriptors'][0];
    $this->run = ['worker' => 'proof-worker', 'key' => $descriptor['key'], 'transport' => $descriptor['transport'],
        'name' => $descriptor['name'], 'input' => $descriptor['input']];
    $this->record = function (string $key, array $result): void {
        $this->postJson($this->path.'/step', ['worker' => 'proof-worker', 'key' => $key.':finish',
            'name' => $key, 'status' => 'succeeded', 'result' => $result])->assertOk();
    };
});

it('admits rename without PHP dispatch and projects only proven source evidence', function (): void {
    Queue::assertNothingPushed();
    expect($this->instance->refresh()->branch)->toBe('old');
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $target, string $name, array $input): bool => $target->id === $this->operation->node_id && $name === 'instance.inspect-development-source'
        && $input === $this->run['input'])->andReturn([
            'origin' => 'https://github.com/example/lifecycle.git', 'branch' => 'feature',
            'stdout' => 'private-environment-value', 'environment' => ['TOKEN' => 'private-environment-value'],
        ]);
    app()->instance(AgentCommandRunner::class, $runner);
    $result = ['branch' => 'feature', 'source_verified' => true];
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data', $result)->assertJsonMissingPath('data.stdout')->assertJsonMissingPath('data.environment');
    $this->postJson($this->path.'/run', $this->run)->assertOk();
    ($this->record)('rename-inspect', $result);
    $finish = ['worker' => 'proof-worker', 'key' => 'lifecycle-finish', 'transport' => 'gateway',
        'name' => 'instance.lifecycle-state', 'input' => ['action' => 'finish', 'operation_id' => $this->operation->id]];
    $receipt = $this->postJson($this->path.'/run', $finish)->assertOk()->json('data');
    expect($this->instance->refresh()->branch)->toBe('feature');
    ($this->record)('lifecycle-finish', $receipt);
    $this->postJson($this->path.'/complete', ['worker' => 'proof-worker', 'status' => 'succeeded', 'result' => $receipt])
        ->assertOk()->assertJsonPath('data.status', 'succeeded');
    expect(json_encode($this->operation->refresh()->toArray()))->not->toContain('private-environment-value')
        ->and($this->operation->status)->toBe(OperationStatus::Succeeded)
        ->and($this->operation->state['executor_lease_node_id'])->toBe($this->services->id)
        ->and($this->operation->state['executor_runs']['rename-inspect']['result'])->toBe($result);
});

it('has one rename dispatch even when a stale direct-writer setting is present', function (): void {
    expect($this->socket->sent)->toBe([])
        ->and($this->operation->state)->not->toHaveKeys(['instance_lifecycle_writer', 'swamp_run_id']);
});

it('projects a lost protected source reply only after its original target identity is proven', function (string $variation): void {
    $definitions = [...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php')];
    // Exercise the source receipt on B while C still gates the complete lifecycle registration.
    $definitions['instance.create']['result_fields'] = ['created', 'error_code'];
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    $this->instance->update(['status' => InstanceState::Reserved]);
    $this->operation->update(['kind' => 'instance.create', 'input' => [...$this->operation->input,
        'initial_status' => 'reserved', 'database_server_id' => null, 'database_clone_source_id' => null,
        'seed_commit' => null, 'task_vm' => false, 'route_domain' => null, 'name' => 'proof',
        'default_branch' => 'main', 'branch_override' => null]]);
    $this->instance->project->update(['source_access' => 'gh_cli']);
    $token = 'ghp_lifecycle_receipt_private_value_592383740982374';
    $cli = Mockery::mock(GitHubCliToken::class);
    $cli->shouldReceive('token')->once()->andReturn($token);
    app()->instance(GitHubCliToken::class, $cli);
    $descriptor = app(InstanceLifecycleExecution::class)->context($this->operation->refresh())['descriptors'][0];
    $run = ['worker' => 'proof-worker', 'key' => $descriptor['key'], 'transport' => $descriptor['transport'],
        'name' => $descriptor['name'], 'input' => $descriptor['input']];
    $effectKey = 'op-'.$this->operation->id.'.source-prepare';
    $receipt = [];
    $identity = [];
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(function (Node $node, string $name, array $input) use ($effectKey, $token, &$receipt, &$identity): bool {
        $envelope = $input[EffectReceiptMetadata::InputKey]->envelope();
        $identity = ['nonce' => $envelope['nonce'], 'created_at' => $envelope['created_at']];
        $receipt = ['effect_key' => $effectKey, 'state' => 'succeeded', 'sanitized' => true,
            'effect_nonce' => $identity['nonce'], 'effect_created_at' => $identity['created_at'], 'exit_code' => 0,
            'result' => ['ok' => true, 'data' => ['created' => true, 'stdout' => 'unpublished source details']], 'finished_at' => now()->toISOString()];
        expect($node->id)->toBe($this->dev->id)
            ->and($name)->toBe('instance.prepare-development-source')
            ->and($input['effect_key'])->toBe($effectKey)
            ->and($input['git_read'])->toBeInstanceOf(GitReadEnvironment::class)
            ->and($input['git_read']->githubToken())->toBe($token)
            ->and($input[EffectReceiptMetadata::InputKey]->envelope()['redactions'])->toContain($token);

        return true;
    })->andThrow(new RuntimeException('Lost source reply'));
    $runner->shouldReceive('run')->times($variation === 'matching' ? 1 : 2)->withArgs(fn (Node $node, string $name, array $input): bool => $node->id === $this->dev->id
        && $name === 'agent.effect-receipt' && $input === ['command' => 'instance.prepare-development-source', 'effect_key' => $effectKey, 'owner' => 'orbit'])
        ->andReturnUsing(function () use (&$receipt): array {
            return $receipt;
        });
    app()->instance(AgentCommandRunner::class, $runner);

    $this->postJson($this->path.'/run', $run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    expect($this->instance->refresh()->status)->toBe(InstanceState::Reserved);
    expect($this->operation->refresh()->state['executor_effect_identity'])->toBe($identity)
        ->and($this->operation->state['executor_runs']['source-prepare']['result'])->toBeNull();
    if ($variation !== 'matching') {
        $original = $receipt;
        match ($variation) {
            'nonce' => $receipt['effect_nonce'] = 'another-'.$identity['nonce'],
            'created_at' => $receipt['effect_created_at']--,
            'missing_identity' => $receipt = array_diff_key($receipt, ['effect_nonce' => true, 'effect_created_at' => true]),
            'unsanitized' => $receipt['sanitized'] = false,
            'common_refusal' => $receipt = [...$receipt, 'state' => 'failed', 'exit_code' => 1,
                'result' => ['ok' => false, 'error' => ['code' => 'agent.effect_conflict']]],
        };
        $recovery = $this->getJson($this->path.'/receipts/source-prepare');
        if ($variation === 'unsanitized') {
            $recovery->assertStatus(502)->assertJsonPath('error.code', 'operation.receipt_unavailable');
        } else {
            $recovery->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
        }
        expect($this->instance->refresh()->status)->toBe(InstanceState::Reserved)
            ->and($this->operation->refresh()->state['executor_runs']['source-prepare']['result'])->toBeNull();
        $this->postJson($this->path.'/complete', ['worker' => 'proof-worker', 'status' => 'failed',
            'error_code' => 'operation.execution_uncertain', 'result' => []])->assertConflict();
        expect($this->operation->refresh()->state['executor_runs']['source-prepare']['result'])->toBeNull();
        $receipt = $original;
    }
    $recovery = $this->getJson($this->path.'/receipts/source-prepare')->assertOk()->assertJsonPath('data.state', 'succeeded')
        ->assertJsonMissingPath('data.effect_nonce')->assertJsonMissingPath('data.effect_created_at')->assertJsonMissingPath('data.sanitized')
        ->assertJsonPath('data.result', ['created' => true]);
    expect($this->instance->refresh()->status)->toBe(InstanceState::CheckoutPrepared)
        ->and($this->operation->refresh()->state['executor_runs']['source-prepare']['result'])->toBe(['created' => true]);
    $this->getJson($this->path.'/receipts/source-prepare')->assertOk()->assertJsonPath('data.result', ['created' => true]);
    $this->postJson($this->path.'/run', $run)->assertOk()->assertJsonPath('data', ['created' => true]);
    expect(json_encode($this->operation->refresh()->toArray()))->not->toContain($token, 'unpublished source details');
})->with(['matching', 'nonce', 'created_at', 'missing_identity', 'unsanitized', 'common_refusal']);

it('composes the real setup child with its immutable digest and retains the parent hold through wait and completion', function (): void {
    $definitions = [...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php')];
    // C still gates the full family; this active-parent path needs only scalar results.
    $definitions['instance.create']['result_fields'] = array_values(array_filter($definitions['instance.create']['result_fields'], is_string(...)));
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    $digest = app(StartInstanceStepListAction::class)->configurationDigest($this->instance->project, LifecyclePhase::Setup);
    $this->instance->update(['starting_commit' => str_repeat('a', 40), 'first_setup_pending' => true]);
    $this->operation->update(['kind' => 'instance.create', 'input' => [...$this->operation->input,
        'initial_status' => 'active', 'first_setup_pending' => true, 'name' => 'proof', 'default_branch' => 'main',
        'branch_override' => 'old', 'database_server_id' => null, 'database_clone_source_id' => null,
        'setup_digest' => $digest, 'teardown_digest' => $digest]]);
    $descriptor = app(InstanceLifecycleExecution::class)->context($this->operation->refresh())['descriptors'][0];
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $target, string $name, array $input): bool => $target->id === $this->dev->id
        && $name === 'instance.inspect-development-source')->andReturn(['branch' => 'old', 'commit' => str_repeat('b', 40)]);
    app()->instance(AgentCommandRunner::class, $runner);
    $source = $this->postJson($this->path.'/run', ['worker' => 'proof-worker', 'key' => $descriptor['key'],
        'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'input' => $descriptor['input']])->assertOk()->json('data');
    ($this->record)('source-inspect', $source);

    config(['swamp.run_token' => 'fixture.shared.token']);
    $socket = new FakeRoutePublicationSocket;
    $runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-instance-setup-direct', 'runId' => $runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $id = $this->postJson($this->path.'/children', ['worker' => 'proof-worker', 'key' => 'setup'])
        ->assertAccepted()->assertJsonPath('data.input', ['instance_id' => $this->instance->id,
            'source' => 'project', 'list' => 'setup', 'configuration_digest' => $digest,
            ...app(StartInstanceStepListAction::class)->appStepAdmission($this->instance, LifecyclePhase::Setup)])
        ->assertJsonPath('data.parent_operation_id', $this->operation->id)->assertJsonPath('data.child_key', 'setup')->json('data.id');
    $this->postJson($this->path.'/children', ['worker' => 'proof-worker', 'key' => 'setup'])->assertOk()->assertJsonPath('data.id', $id);
    $hold = $this->operation->refresh()->lock_key;
    expect($hold)->not->toBeNull()->and(Operation::query()->findOrFail($id)->lock_key)->toBeNull();
    $this->postJson($this->path.'/wait', ['worker' => 'proof-worker', 'keys' => ['setup']])->assertOk()->assertJsonPath('data.status', 'waiting');
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.run_steps', 'worker' => 'setup-worker', 'operation_id' => $id])->assertUnprocessable();
    $child = '/api/v1/direct-operations/'.$id;
    $this->getJson($child.'?run_id='.$runId)->assertOk()->assertJsonPath('data.input.list', 'setup');
    $result = ['instance_id' => $this->instance->id, 'outcome' => 'succeeded', 'no_op' => true];
    $reply = $this->postJson($child.'/result', ['run_id' => $runId, 'status' => 'succeeded', 'started_at' => now()->subSecond()->utc()->format('Y-m-d\TH:i:s.v\Z'),
        'finished_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'result' => $result, 'error_code' => null]);
    $reply->assertOk();
    expect($this->operation->refresh()->lock_key)->toBe($hold);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.create', 'worker' => 'resumed-parent', 'operation_id' => $this->operation->id])
        ->assertOk()->assertJsonPath('data.id', $this->operation->id);
    $finish = $this->postJson($this->path.'/run', ['worker' => 'resumed-parent', 'key' => 'lifecycle-finish', 'transport' => 'gateway',
        'name' => 'instance.lifecycle-state', 'input' => ['action' => 'finish', 'operation_id' => $this->operation->id]])
        ->assertOk()->assertJsonPath('data.lifecycle_complete', true)->json('data');
    $this->postJson($this->path.'/step', ['worker' => 'resumed-parent', 'key' => 'finish', 'name' => 'lifecycle-finish', 'status' => 'succeeded', 'result' => $finish])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'resumed-parent', 'status' => 'succeeded', 'result' => $finish])->assertOk();
    expect($this->operation->refresh()->lock_key)->toBeNull()->and($this->instance->refresh()->first_setup_pending)->toBeFalse();
});

it('classifies transport-proven source metadata before filtering and records only its safe outcome', function (bool $valid): void {
    $definitions = [...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php')];
    $definitions['instance.create']['result_fields'] = ['error_code', 'php_version', 'laravel'];
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    $this->instance->update(['status' => InstanceState::SourceResolved, 'provisioning_step' => null, 'source_is_laravel' => null]);
    $binding = ['step' => 'source-resolve', 'transport' => 'instance_lifecycle', 'name' => 'instance.resolve-development-source', 'input' => []];
    $this->operation->update(['kind' => 'instance.create', 'input' => [...$this->operation->input,
        'initial_status' => 'reserved', 'database_server_id' => null, 'database_clone_source_id' => null,
        'seed_commit' => null, 'task_vm' => false, 'route_domain' => null, 'name' => 'proof',
        'default_branch' => 'main', 'branch_override' => null], 'state' => [...$this->operation->state,
            'lifecycle_bindings' => ['source-resolve' => $binding],
            'executor_runs' => ['source-resolve' => [...$binding, 'result' => ['branch' => 'proof', 'commit' => str_repeat('a', 40)]]]]]);
    $this->operation->steps()->create(['name' => 'source-resolve', 'position' => 1, 'sequence' => 1, 'status' => 'succeeded', 'resumable' => true]);
    $descriptor = array_find(app(InstanceLifecycleExecution::class)->context($this->operation)['descriptors'], static fn (array $descriptor): bool => $descriptor['key'] === 'source-profile');
    $composer = $valid ? json_encode(['require' => ['php' => '^8.5', 'laravel/framework' => '^13.0']], JSON_THROW_ON_ERROR) : 'malformed composer bytes';
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $node, string $name, array $input): bool => $node->id === $this->dev->id
        && $name === 'instance.inspect-development-profile' && $input === $descriptor['input'])
        ->andReturn(['kind' => 'composer', 'composer' => base64_encode($composer), 'entry_point' => 'regular', 'stdout' => 'unpublished-profile-metadata']);
    app()->instance(AgentCommandRunner::class, $runner);
    $expected = $valid ? ['php_version' => '8.5', 'laravel' => true] : ['error_code' => 'app-dev.php_version_unsupported'];

    $this->postJson($this->path.'/run', ['worker' => 'proof-worker', 'key' => 'source-profile', 'transport' => $descriptor['transport'],
        'name' => $descriptor['name'], 'input' => $descriptor['input']])->assertOk()->assertJsonPath('data', $expected);

    expect($this->operation->refresh()->state['executor_runs']['source-profile']['result'])->toBe($expected);
    expect($this->instance->refresh()->source_is_laravel)->toBe($valid ? true : null);
    expect(json_encode($this->operation->toArray()))->not->toContain(base64_encode($composer), 'unpublished-profile-metadata');
})->with([true, false]);

it('admits and replays development creation without executing source or setup', function (string $requested, string $expected): void {
    $this->instance->project->update(['type' => 'monorepo']);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);
    $destination = Mockery::mock(InstanceDestinationGuard::class);
    if ($expected === 'default') {
        $destination->shouldReceive('assertUnoccupied')->once();
    } else {
        $destination->shouldNotReceive('assertUnoccupied');
    }
    app()->instance(InstanceDestinationGuard::class, $destination);
    $this->withServerVariables(['REMOTE_ADDR' => $this->dev->wireguard_ip])->withHeader('Idempotency-Key', 'create-admission-proof');
    $body = ['project_id' => $this->instance->project_id, 'node_id' => $this->dev->id, 'name' => $requested];
    $id = $this->postJson('/api/v1/instances', $body)->assertAccepted()
        ->assertJsonPath('data.kind', 'instance.create')->assertJsonPath('data.status', 'queued')->json('data.id');
    $this->postJson('/api/v1/instances', $body)->assertAccepted()->assertJsonPath('data.id', $id);
    $operation = Operation::query()->findOrFail($id);
    $instance = Instance::query()->findOrFail($operation->target_id);
    expect($instance->status)->toBe(InstanceState::Reserved)->and($instance->source_prepare_id)->not->toBeNull()
        ->and($instance->first_setup_pending)->toBeTrue()->and($instance->name)->toBe($expected)
        ->and($operation->node_id)->toBe($this->dev->id)->and($operation->input['name'])->toBe($expected)
        ->and($operation->input['setup_digest'])->toBe(hash('sha256', '[]'));
    Queue::assertNothingPushed();
})->with([['isolated', 'isolated'], ['main', 'default']]);

it('refuses changed target, command, input and descriptor keys before invoking an agent', function (string $change): void {
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);
    $run = $this->run;
    match ($change) {
        'node' => $run['node_id'] = $this->services->id,
        'agent' => $run['name'] = 'instance.remove-production-home',
        'program' => [$run['transport'] = 'remote_program', $run['name'] = 'instance.setup'],
        'checkout' => $run['input']['checkout'] = '/srv/apps/other/default',
        'extra' => $run['input']['arbitrary'] = true,
        'key' => $run['key'] = 'unadmitted',
    };
    $this->postJson($this->path.'/run', $run)->assertUnprocessable()
        ->assertJsonPath('error.code', 'operation.execution_forbidden');
})->with(['node', 'agent', 'program', 'checkout', 'extra', 'key']);

it('does not accept bare completion, a forged source step or an unproven finish', function (): void {
    $this->postJson($this->path.'/complete', ['worker' => 'proof-worker', 'status' => 'succeeded', 'result' => ['lifecycle_complete' => true]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson($this->path.'/step', ['worker' => 'proof-worker', 'key' => 'forged', 'name' => 'rename-inspect',
        'status' => 'succeeded', 'result' => ['source_verified' => true, 'branch' => 'feature']])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson($this->path.'/run', ['worker' => 'proof-worker', 'key' => 'lifecycle-finish', 'transport' => 'gateway',
        'name' => 'instance.lifecycle-state', 'input' => ['action' => 'finish', 'operation_id' => $this->operation->id]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    expect($this->instance->refresh()->branch)->toBe('old');
});

it('rejects a source from another repository or a branch that is not checked out', function (string $change): void {
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(['origin' => $change === 'origin'
        ? 'https://github.com/example/other.git' : 'https://github.com/example/lifecycle.git',
        'branch' => $change === 'branch' ? 'wrong' : 'feature']);
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.error_code',
        $change === 'origin' ? 'instance.source_identity_invalid' : 'instance.branch_not_checked_out');
    expect($this->instance->refresh()->branch)->toBe('old');
})->with(['origin', 'branch']);

it('requires the configured active executor identity and its current lease', function (): void {
    $this->dev->accessibleNodes()->attach($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->dev->wireguard_ip]);
    $this->postJson($this->path.'/run', $this->run)->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
    $this->withServerVariables(['REMOTE_ADDR' => $this->services->wireguard_ip]);
    $this->travel(121)->seconds();
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.lease_lost');
});

final class LifecycleRouteAdmissionFixture implements OperationChildAdmission
{
    public function admit(Operation $parent, array $intent): Operation
    {
        $route = Route::query()->findOrFail($intent['input']['route_id']);
        if (! $route->targets()->where('instance_id', $parent->target_id)->exists()) {
            throw new ResourceOperationException('operation.child_forbidden', 'The Route does not belong to this Instance.', 422);
        }

        return app(StartOperationAction::class)->execute('route.update', 'route', $route->id,
            $intent['node_id'], [...$intent['input'], 'owning_admission' => true], 'test', null);
    }
}

final readonly class LifecycleRouteCommandFixture implements OperationCompletion, OperationGatewayCommand
{
    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['route_id', 'domain'],
            'properties' => ['route_id' => ['type' => 'integer'], 'domain' => ['type' => 'string']]];
    }

    public function run(Operation $operation, #[SensitiveParameter] array $input): array
    {
        $route = Route::query()->findOrFail($input['route_id']);
        $replacement = Route::query()->create(['project_id' => $route->project_id, 'node_id' => $route->node_id,
            'domain' => $input['domain'], 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending',
            'replaces_route_id' => $route->id, 'replacement_step' => RouteReplacementStep::Reserved]);
        $route->update(['replaced_by_route_id' => $replacement->id]);
        $replacement->targets()->create(['instance_id' => Operation::query()->findOrFail($operation->parent_operation_id)->target_id, 'position' => 0]);
        $route->update(['status' => 'retiring', 'replacement_step' => RouteReplacementStep::DatabaseCutover]);
        $replacement->update(['status' => 'active', 'replacement_step' => RouteReplacementStep::DatabaseCutover]);

        return ['updated' => true];
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}

it('admits the exact Route child only after source proof and requires its stamped success before rename finish', function (): void {
    $route = Route::query()->create(['project_id' => $this->instance->project_id, 'node_id' => $this->dev->id,
        'domain' => 'old.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $this->operation->update(['input' => [...$this->operation->input, 'route_id' => $route->id, 'domain' => 'preview.test']]);
    $definitions = [...OperationKinds::definitions(),
        ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
        'route.update' => ['executor' => 'swamp', 'completion' => LifecycleRouteCommandFixture::class,
            'admission' => LifecycleRouteAdmissionFixture::class,
            'runs' => [['transport' => 'gateway', 'name' => 'test.lifecycle-route-update', 'input_fields' => ['route_id', 'domain'], 'resumable' => true]],
            'completion_contract' => ['step' => 'route-update', 'transport' => 'gateway', 'name' => 'test.lifecycle-route-update', 'result' => ['updated' => true]],
            'result_fields' => ['error_code', 'updated']]];
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    app()->instance(OperationGatewayCommands::class, new OperationGatewayCommands([
        'instance.lifecycle-state' => InstanceLifecycleCommand::class,
        'test.lifecycle-route-update' => LifecycleRouteCommandFixture::class,
    ]));
    $this->postJson($this->path.'/children', ['worker' => 'proof-worker', 'key' => 'route-update'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'operation.child_forbidden');
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(['origin' => 'https://github.com/example/lifecycle.git', 'branch' => 'feature']);
    app()->instance(AgentCommandRunner::class, $runner);
    $result = $this->postJson($this->path.'/run', $this->run)->assertOk()->json('data');
    ($this->record)('rename-inspect', $result);
    $this->getJson('/api/v1/operations/'.$this->operation->id.'/instance-lifecycle')->assertOk()
        ->assertJsonPath('data.child_context.children.0.input', ['route_id' => $route->id, 'domain' => 'preview.test']);
    $this->postJson($this->path.'/children', ['worker' => 'proof-worker', 'key' => 'route-update', 'input' => ['route_id' => $route->id, 'domain' => 'foreign.test']])
        ->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $id = $this->postJson($this->path.'/children', ['worker' => 'proof-worker', 'key' => 'route-update'])
        ->assertAccepted()->assertJsonPath('data.parent_operation_id', $this->operation->id)
        ->assertJsonPath('data.child_key', 'route-update')->assertJsonPath('data.input.owning_admission', true)->json('data.id');
    $this->postJson($this->path.'/children', ['worker' => 'proof-worker', 'key' => 'route-update'])->assertOk()->assertJsonPath('data.id', $id);
    $finish = ['worker' => 'proof-worker', 'key' => 'lifecycle-finish', 'transport' => 'gateway', 'name' => 'instance.lifecycle-state',
        'input' => ['action' => 'finish', 'operation_id' => $this->operation->id]];
    $this->postJson($this->path.'/run', $finish)->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
    $this->postJson($this->path.'/wait', ['worker' => 'proof-worker', 'keys' => ['route-update']])
        ->assertOk()->assertJsonPath('data.status', 'waiting')->assertJsonPath('data.worker', null);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.rename', 'worker' => 'resumed-parent', 'operation_id' => $this->operation->id])
        ->assertOk()->assertJsonPath('data', null);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'route.update', 'worker' => 'route-child', 'operation_id' => $id])->assertOk()->assertJsonPath('data.id', $id);
    $childPath = '/api/v1/operation-executor/'.$id;
    $this->postJson($childPath.'/run', ['worker' => 'route-child', 'key' => 'route-update', 'transport' => 'gateway', 'name' => 'test.lifecycle-route-update',
        'input' => ['route_id' => $route->id, 'domain' => 'preview.test']])->assertOk();
    $this->postJson($childPath.'/step', ['worker' => 'route-child', 'key' => 'done', 'name' => 'route-update', 'status' => 'succeeded', 'result' => ['updated' => true]])->assertOk();
    $this->postJson($childPath.'/complete', ['worker' => 'route-child', 'status' => 'succeeded', 'result' => ['updated' => true]])->assertOk();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.rename', 'worker' => 'resumed-parent', 'operation_id' => $this->operation->id])
        ->assertOk()->assertJsonPath('data.id', $this->operation->id);
    $finish['worker'] = 'resumed-parent';
    $child = Operation::query()->findOrFail($id);
    $original = $child->input;
    $child->update(['input' => [...$original, 'domain' => 'foreign.test']]);
    $this->postJson($this->path.'/run', $finish)->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
    expect($this->instance->refresh()->branch)->toBe('old');
    $child->update(['input' => $original]);
    $this->postJson($this->path.'/run', $finish)->assertOk()->assertJsonPath('data.lifecycle_complete', true);
    expect($this->instance->refresh()->branch)->toBe('feature')->and($this->instance->routes()->where('domain', 'preview.test')->exists())->toBeTrue();
});

it('refuses new lifecycle intent before changing state when required kit capabilities are missing', function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), []));
    $this->operation->update(['status' => 'failed', 'lock_key' => null]);
    $before = $this->instance->refresh()->getAttributes();
    $count = Operation::query()->count();
    $this->withServerVariables(['REMOTE_ADDR' => $this->dev->wireguard_ip]);
    $this->postJson('/api/v1/instances/'.$this->instance->id.'/rename', ['branch' => 'another'])
        ->assertStatus(503)->assertJsonPath('error.code', 'operation.kind_unavailable');
    expect($this->instance->refresh()->getAttributes())->toBe($before)->and(Operation::query()->count())->toBe($count);
});

it('rejects a fabricated failed lifecycle completion without run or child evidence', function (): void {
    $this->postJson($this->path.'/complete', ['worker' => 'proof-worker', 'status' => 'failed', 'error_code' => 'instance.fabricated'])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    expect($this->instance->refresh()->branch)->toBe('old');
});

final readonly class LifecycleListFixture implements OperationChildAdmission, OperationCompletion, OperationGatewayCommand
{
    public function admit(Operation $parent, array $intent): Operation
    {
        return app(StartOperationAction::class)->execute('instance.run_steps', 'instance', $parent->target_id,
            $intent['node_id'], $intent['input'], 'test', null);
    }

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['instance_id'],
            'properties' => ['instance_id' => ['type' => 'integer']]];
    }

    public function run(Operation $operation, #[SensitiveParameter] array $input): array
    {
        return ['outcome' => 'failed', 'error_code' => null];
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}

it('requires a successful typed list outcome in addition to a succeeded child Operation', function (): void {
    $input = [...$this->operation->input, 'run_setup' => true, 'setup_digest' => str_repeat('a', 64),
        'members' => [['instance_id' => $this->instance->id, 'completed' => true, 'route_id' => null,
            'original_path' => $this->instance->checkout_path, 'checkout' => $this->instance->checkout_path, 'relocation_state' => 'relocated']]];
    $this->operation->update(['kind' => 'instance.register', 'input' => $input]);
    app()->instance(OperationKinds::class, new OperationKinds(app(), [
        ...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
        'instance.run_steps' => ['executor' => 'swamp', 'completion' => LifecycleListFixture::class, 'admission' => LifecycleListFixture::class,
            'runs' => [['transport' => 'gateway', 'name' => 'test.incoherent-list', 'input_fields' => ['instance_id'], 'resumable' => true]],
            'completion_contract' => ['step' => 'list', 'transport' => 'gateway', 'name' => 'test.incoherent-list', 'result' => ['outcome' => 'failed']],
            'result_fields' => ['outcome', 'error_code']],
    ]));
    app()->instance(OperationGatewayCommands::class, new OperationGatewayCommands([
        'test.incoherent-list' => LifecycleListFixture::class,
    ]));
    $id = $this->postJson($this->path.'/children', ['worker' => 'proof-worker', 'key' => 'setup'])->assertAccepted()->json('data.id');
    expect($this->operation->refresh()->lock_key)->not->toBeNull()->and(Operation::query()->findOrFail($id)->lock_key)->toBeNull();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.run_steps', 'worker' => 'list-child', 'operation_id' => $id])->assertOk();
    $child = '/api/v1/operation-executor/'.$id;
    $result = $this->postJson($child.'/run', ['worker' => 'list-child', 'key' => 'list', 'transport' => 'gateway', 'name' => 'test.incoherent-list',
        'input' => ['instance_id' => $this->instance->id]])->assertOk()->json('data');
    $this->postJson($child.'/step', ['worker' => 'list-child', 'key' => 'done', 'name' => 'list', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($child.'/complete', ['worker' => 'list-child', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect(fn () => app(InstanceLifecycleExecution::class)->assertStepList($this->operation, 'setup'))
        ->toThrow(ResourceOperationException::class, 'The lifecycle list did not prove a successful outcome.');
});
