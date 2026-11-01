<?php

declare(strict_types=1);

use App\Actions\Instances\RenameInstanceAction;
use App\Data\Instances\RenameInstanceData;
use App\Domain\Instances\InstanceRenameResult;
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
    $this->project = Project::query()->create(['name' => 'Rename proof', 'slug' => 'rename-proof', 'repository_url' => 'https://github.com/example/proof.git', 'type' => 'node-package', 'root' => '.']);
    $this->node = Node::query()->create(['name' => 'rename-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']);
    orbit_test_set_app_placement_role($this->node, false);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'proof', 'checkout_path' => '/srv/apps/rename-proof/proof', 'source_layout' => 'checkout', 'status' => 'active', 'branch' => 'old']);
    $this->operation = Operation::query()->create(['status' => 'queued', 'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'kind' => 'instance.rename', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'input' => ['branch' => 'feature']]);
});

it('projects a successful native branch result without running a Node command', function (): void {
    app(InstanceRenameResult::class)->apply($this->operation, ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'succeeded', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => ['branch' => 'feature']]);
    expect($this->instance->refresh()->branch)->toBe('feature')->and($this->instance->branch_override)->toBe('feature');
});

it('keeps the recorded branch when the native action fails', function (): void {
    app(InstanceRenameResult::class)->apply($this->operation, ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'failed', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'error_code' => 'instance.branch_not_checked_out', 'result' => ['error_code' => 'instance.branch_not_checked_out']]);
    expect($this->instance->refresh()->branch)->toBe('old');
});

it('rejects domain replacement before admission while its native Route parent is unavailable', function (): void {
    expect(fn () => app(RenameInstanceAction::class)->execute($this->instance, new RenameInstanceData('feature', 'new.example.test')))
        ->toThrow(ResourceOperationException::class, 'Domain replacement requires');
    expect(Operation::query()->count())->toBe(1)->and($this->instance->refresh()->branch)->toBe('old');
});

it('returns only recorded public intent to the native reader', function (): void {
    expect(app(InstanceRenameResult::class)->input($this->operation)['intent'])->toMatchArray($this->operation->input)
        ->and(app(InstanceRenameResult::class)->input($this->operation)['intent']['ssh_execution']['target']['host_keys'])->toBe([$this->node->ssh_host_key_type.' '.$this->node->ssh_host_key]);
});

it('admits one root and projects its result through the real HTTP boundary', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'rename-gateway', 'status' => 'active',
        'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node->accessibleNodes()->attach($gateway);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $agent = Mockery::mock(AgentCommandRunner::class);
    $agent->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agent);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'native-rename-fixture']);
    $path = '/api/v1/instances/'.$this->instance->id.'/rename';

    $response = $this->withHeader('Idempotency-Key', 'rename.native.fixture')->postJson($path, ['branch' => 'feature'])->assertAccepted();
    $id = $response->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    app(StartSwampRun::class)->start($operation);

    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-instance-rename');
    expect($socket->sent[0]['payload']['inputs'])->toMatchArray(['operation_id' => $id, 'kind' => 'instance.rename', 'node_id' => $this->node->id])
        ->and($socket->sent[0]['payload']['inputs']['intent']['checkout'])->toBe($operation->input['checkout']);
    expect($this->instance->refresh()->branch)->toBe('old');
    $this->postJson($path, ['branch' => 'feature'])->assertAccepted()->assertJsonPath('data.id', $id);
    $this->withoutHeader('Idempotency-Key');
    $result = ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'succeeded',
        'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => ['branch' => 'feature']];
    $history = ['id' => $result['run_id'], 'workflowName' => 'operation-instance-rename',
        'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id],
        'status' => 'succeeded', 'startedAt' => $result['started_at'], 'completedAt' => $result['finished_at'],
        'jobs' => [['name' => 'source', 'status' => 'succeeded', 'steps' => [['name' => 'result', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode($result['result']), 'exitCode' => 0]]]]]];
    config(['swamp.read_token' => 'fixture.read']);
    $socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $history]]];
    app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $result['run_id'], 'status' => 'succeeded'], ['job' => 'source', 'step' => 'result']);
    app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $result['run_id'], 'status' => 'succeeded'], ['job' => 'source', 'step' => 'result']);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run', 'workflow.history.get']);
    expect($operation->refresh()->lock_key)->toBeNull();
    expect($this->instance->refresh()->branch)->toBe('feature');
    expect($this->instance->branch_override)->toBe('feature');
    expect($operation->state)->not->toHaveKeys(['executor_runs', 'executor_children']);
});

it('preserves an existing override when recording the same branch', function (): void {
    $this->instance->update(['branch' => 'feature', 'branch_override' => null]);

    app(InstanceRenameResult::class)->apply($this->operation, ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
        'status' => 'succeeded', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => ['branch' => 'feature']]);

    expect($this->instance->refresh()->branch_override)->toBeNull();
});
