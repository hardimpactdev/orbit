<?php

declare(strict_types=1);

use App\Actions\Instances\RegisterInstanceAction;
use App\Data\Instances\RegisterInstanceData;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\InstanceRegisterResult;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'Register proof', 'slug' => 'register-proof', 'repository_url' => 'https://github.com/example/proof.git', 'type' => 'node-package', 'root' => '.']);
    $this->node = Node::query()->create(['name' => 'register-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG', 'settings' => ['apps' => ['path' => '/srv/apps']]]);
    orbit_test_set_app_placement_role($this->node, false);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'proof', 'checkout_path' => '/srv/apps/register-proof/proof', 'source_layout' => 'checkout', 'status' => 'reserved']);
    $this->operation = Operation::query()->create(['status' => 'queued', 'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'kind' => 'instance.register', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'input' => ['checkout' => $this->instance->checkout_path, 'repository' => $this->project->repository_url, 'repository_identity' => $this->project->repository_identity]]);
    $this->result = ['registered' => true, 'php_version' => null, 'laravel' => false, 'branch' => 'feature', 'commit' => str_repeat('a', 40), 'detached' => false, 'digest' => str_repeat('b', 64), 'common' => $this->instance->checkout_path.'/.git', 'default_branch' => 'main', 'inferred_slug' => 'proof'];
});

it('projects native adoption and source facts into the reserved Instance', function (): void {
    $hook = app(InstanceRegisterResult::class);
    $hook->apply($this->operation, ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'succeeded', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => $this->result]);
    expect($this->instance->refresh()->source_is_laravel)->toBeFalse();
    expect($this->instance->status->value)->toBe('active')
        ->and($this->instance->branch)->toBe('feature')->and($this->instance->starting_commit)->toBe(str_repeat('a', 40))
        ->and($this->instance->registration_relocation_state)->toBe('relocated')->and($this->instance->registration_completed_at)->not->toBeNull();
});

it('keeps failed adoption reserved for a later retry', function (): void {
    app(InstanceRegisterResult::class)->apply($this->operation, ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'failed', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'error_code' => 'instance.source_invalid', 'result' => ['error_code' => 'instance.source_invalid']]);
    expect($this->instance->refresh()->status->value)->toBe('reserved')->and($this->instance->registration_completed_at)->toBeNull();
});

it('refuses unsupported registration before another row or Operation is reserved', function (array $change): void {
    $values = [...['sourcePath' => '/srv/apps/register-proof/other', 'includeWorktrees' => false, 'projectId' => $this->project->id, 'instanceName' => 'other', 'root' => null, 'domain' => null, 'runSetup' => false], ...$change];
    expect(fn () => app(RegisterInstanceAction::class)->execute($this->node, new RegisterInstanceData(...$values)))->toThrow(ResourceOperationException::class, 'Native registration currently');
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
})->with([
    'worktrees' => [['includeWorktrees' => true]], 'setup' => [['runSetup' => true]],
    'project inference' => [['projectId' => null]], 'domain' => [['domain' => 'proof.test']],
    'relocation' => [['sourcePath' => '/work/proof']],
]);

it('returns only recorded public intent to the native reader', function (): void {
    expect(app(InstanceRegisterResult::class)->input($this->operation)['intent'])->toMatchArray($this->operation->input)
        ->and(app(InstanceRegisterResult::class)->input($this->operation)['intent']['ssh_execution']['target']['host_keys'])->toBe([$this->node->ssh_host_key_type.' '.$this->node->ssh_host_key]);
});

it('admits and completes in-place registration through one real native HTTP root', function (): void {
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
    expect($operation->lock_key)->toBe('instance:'.$instance->id);
    expect($instance->status->value)->toBe('reserved')->and($instance->branch)->toBeNull();
    app(StartSwampRun::class)->start($operation);
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-instance-register');
    expect($socket->sent[0]['payload']['inputs'])->toMatchArray(['operation_id' => $id, 'kind' => 'instance.register', 'node_id' => $this->node->id])
        ->and($socket->sent[0]['payload']['inputs']['intent']['checkout'])->toBe($operation->input['checkout']);
    $this->postJson('/api/v1/instances/register', $body)->assertAccepted()->assertJsonPath('data.id', $id);
    $this->withoutHeader('Idempotency-Key');
    $result = ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'succeeded',
        'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'),
        'result' => [...$this->result, 'common' => $instance->checkout_path.'/.git']];
    $history = ['id' => $result['run_id'], 'workflowName' => 'operation-instance-register',
        'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id],
        'status' => 'succeeded', 'startedAt' => $result['started_at'], 'completedAt' => $result['finished_at'],
        'jobs' => [['name' => 'source', 'status' => 'succeeded', 'steps' => [['name' => 'result', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode($result['result']), 'exitCode' => 0]]]]]];
    config(['swamp.read_token' => 'fixture.read']);
    $socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $history]]];
    app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $result['run_id'], 'status' => 'succeeded'], ['job' => 'source', 'step' => 'result']);
    app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $result['run_id'], 'status' => 'succeeded'], ['job' => 'source', 'step' => 'result']);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run', 'workflow.history.get']);
    expect($operation->refresh()->lock_key)->toBeNull();
    expect($instance->refresh()->source_is_laravel)->toBeFalse();
    expect(app(InstanceEnvironmentContextResolver::class)->resolve($instance, true)->laravel)->toBeFalse();
    expect($instance->status->value)->toBe('active')->and($instance->branch)->toBe('feature');
    expect($instance->registration_completed_at)->not->toBeNull();
    expect($operation->input)->not->toHaveKey('agent_sha256');
});
