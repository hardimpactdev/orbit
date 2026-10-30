<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationDispatcher;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationSweeper;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Activity;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\PolledOperationFixture;

function executorNode(string $name, string $ip): Node
{
    $node = Node::query()->create(['name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20', 'public_ssh_port' => 22, 'user' => 'orbit', 'wireguard_ip' => $ip]);
    $node->accessibleNodes()->attach($node);

    return $node;
}

function executorAdmission($test): array
{
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'test.remote' => PolledOperationFixture::definition()]));
    $node = executorNode('dev', '10.44.0.3');
    $gateway = executorNode('gateway', '10.44.0.1');
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node->accessibleNodes()->attach($gateway);
    $test->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $operation = app(StartOperationAction::class)->execute('test.remote', 'node', $node->id, $node->id,
        ['name' => 'proof', 'action' => 'deny', 'source' => '192.0.2.1/32', 'protocol' => 'tcp', 'port' => '65431'], 'test', null);

    return [$node, $operation->id];
}

function asExecutor($test): Node
{
    $services = executorNode('services', '10.44.0.4');
    $gateway = Node::query()->where('name', 'gateway')->sole();
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $test->withoutHeader('Idempotency-Key')->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);

    return $services;
}

/** The exact command input admitted by the Operation. */
function executorRunInput(int $id, string $worker = 'run-1'): array
{
    $operation = Operation::findOrFail($id);
    $input = array_intersect_key($operation->input, array_flip(['name', 'action', 'source', 'port', 'protocol']));

    return ['worker' => $worker, 'key' => 'ufw', 'transport' => 'agent', 'name' => 'firewall.rule',
        'input' => [...$input, 'node_id' => $operation->node_id, 'operation' => 'converge']];
}

function recordExecutorProof($test, int $id, string $backend = 'active', string $worker = 'run-1'): void
{
    $step = 'effect';
    $test->postJson("/api/v1/operation-executor/{$id}/run", executorRunInput($id, $worker))->assertOk();
    $test->postJson("/api/v1/operation-executor/{$id}/step", [
        'worker' => $worker, 'key' => 'finish', 'name' => $step, 'status' => 'succeeded', 'result' => ['backend' => $backend],
    ])->assertOk();
}

it('restricts claims to Services and to registered Swamp kinds', function (): void {
    executorAdmission($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-1'])
        ->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
    asExecutor($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'unregistered.kind', 'worker' => 'run-1'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'operation.executor_invalid');
});

it('replays a claim and fences the previous worker after lease expiry', function (): void {
    [, $id] = executorAdmission($this);
    asExecutor($this);
    foreach (range(1, 2) as $_) {
        $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-1'])
            ->assertOk()->assertJsonPath('data.id', $id);
    }
    expect(Operation::findOrFail($id)->attempts)->toBe(1);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-2'])->assertOk()->assertJsonPath('data', null);
    $this->travel(121)->seconds();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-2'])->assertOk()->assertJsonPath('data.id', $id);
    $this->postJson("/api/v1/operation-executor/{$id}/heartbeat", ['worker' => 'run-1'])->assertConflict()->assertJsonPath('error.code', 'operation.lease_lost');
    $this->postJson("/api/v1/operation-executor/{$id}/heartbeat", ['worker' => 'run-2'])->assertOk();
});

it('parks an exhausted Operation without a sixth execution or dropping its target hold', function (bool $sameWorker): void {
    $this->freezeTime();
    [, $id] = executorAdmission($this);
    asExecutor($this);
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'reclaim-'.$attempt])
            ->assertOk()->assertJsonPath('data.id', $id);
        expect(Operation::findOrFail($id)->attempts)->toBe($attempt);
        if ($attempt < 5) {
            $this->travel(121)->seconds();
        }
    }
    $this->postJson('/api/v1/operation-executor/'.$id.'/heartbeat', ['worker' => 'reclaim-5'])->assertOk();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'reclaim-6'])->assertOk()->assertJsonPath('data', null);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'reclaim-5'])->assertOk()->assertJsonPath('data.id', $id);
    expect(Operation::findOrFail($id)->attempts)->toBe(5);
    $this->travel(121)->seconds();
    $before = Operation::findOrFail($id)->getRawOriginal();
    $this->getJson('/api/v1/operation-executor/claimable')->assertOk();
    expect(Operation::findOrFail($id)->getRawOriginal())->toBe($before);
    $claim = ['kind' => 'test.remote', 'worker' => $sameWorker ? 'reclaim-5' : 'reclaim-6'];
    $this->postJson('/api/v1/operation-executor/claim', $claim)->assertOk()->assertJsonPath('data', null);
    $operation = Operation::findOrFail($id);
    expect($operation->status)->toBe(OperationStatus::Waiting)
        ->and($operation->attempts)->toBe(5)
        ->and($operation->lock_key)->not->toBeNull()
        ->and($operation->state['executor_assistance']['error_code'])->toBe('operation.reclaim_limit')
        ->and($operation->steps()->count())->toBe(0);
    $this->getJson('/api/v1/operations/'.$id)->assertOk()->assertJsonPath('data.assistance.error_code', 'operation.reclaim_limit');
    $sequence = $operation->sequence;
    $this->postJson('/api/v1/operation-executor/claim', $claim)->assertOk()->assertJsonPath('data', null);
    expect($operation->refresh()->sequence)->toBe($sequence);
})->with([false, true]);

it('resumes a reclaim-limit stop once with its receipts, hold and audited caller intact', function (): void {
    $this->freezeTime();
    [$node, $id] = executorAdmission($this);
    $services = asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(['backend' => 'active']);
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'before-limit'])->assertOk();
    recordExecutorProof($this, $id, worker: 'before-limit');
    $operation = Operation::findOrFail($id);
    $operation->update(['attempts' => 5]);
    $this->travel(121)->seconds();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'exhausted'])->assertJsonPath('data', null);
    $operation->refresh();
    expect([$operation->status->value, $operation->state['executor_assistance'] ?? null])->toBe(['waiting', ['error_code' => 'operation.reclaim_limit']]);
    $state = $operation->state;
    unset($state['executor_assistance']);
    $steps = $operation->steps()->get()->toArray();
    $sequence = $operation->sequence;
    $hold = $operation->lock_key;
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

    $this->postJson("/api/v1/operations/{$id}/resume", [], options: JSON_FORCE_OBJECT)->assertOk()
        ->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.attempts', 0)->assertJsonPath('data.assistance', null)
        ->assertJsonPath('data.sequence', $sequence + 1)->assertJsonCount(1, 'data.steps');

    expect($operation->refresh()->state)->toBe($state)
        ->and($operation->lock_key)->toBe($hold)
        ->and($operation->steps()->get()->toArray())->toBe($steps);
    $activity = Activity::query()->where('command', 'operation:resume')->sole();
    expect($activity->status)->toBe('succeeded')
        ->and($activity->caller_node_id)->toBe($node->id)
        ->and($activity->subject_type)->toBe(Operation::class)
        ->and($activity->subject_id)->toBe($id)
        ->and($activity->request_id)->not->toBeEmpty();
    $before = $operation->getRawOriginal();
    $this->postJson("/api/v1/operations/{$id}/resume", [], options: JSON_FORCE_OBJECT)->assertConflict()->assertJsonPath('error.code', 'operation.not_resumable');
    expect($operation->refresh()->getRawOriginal())->toBe($before);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'after-limit'])
        ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.attempts', 1);
    $this->postJson("/api/v1/operation-executor/{$id}/run", executorRunInput($id, 'after-limit'))->assertJsonPath('data.backend', 'active');
    $this->postJson("/api/v1/operation-executor/{$id}/complete", ['worker' => 'after-limit', 'status' => 'succeeded', 'result' => ['backend' => 'active']])
        ->assertOk()->assertJsonPath('data.status', 'succeeded');
});

it('refuses resume with 409 unless Swamp work is parked at reclaim limit without cancellation', function (array $changes): void {
    [, $id] = executorAdmission($this);
    $operation = Operation::findOrFail($id);
    $operation->update(['status' => 'waiting', 'attempts' => 5, 'state' => ['executor_assistance' => ['error_code' => 'operation.reclaim_limit']], ...$changes]);
    $before = $operation->getRawOriginal();

    $this->withoutHeader('Idempotency-Key')->postJson("/api/v1/operations/{$id}/resume", [], options: JSON_FORCE_OBJECT)
        ->assertConflict()->assertJsonPath('error.code', 'operation.not_resumable');
    expect($operation->refresh()->getRawOriginal())->toBe($before);
})->with([
    'running' => [['status' => 'running']],
    'queued' => [['status' => 'queued']],
    'succeeded' => [['status' => 'succeeded']],
    'failed' => [['status' => 'failed']],
    'cancelled' => [['status' => 'cancelled']],
    'unproven child' => [['state' => ['executor_assistance' => ['error_code' => 'operation.child_unproven']]]],
    'ordinary child wait' => [['state' => []]],
    'malformed assistance' => [['state' => ['executor_assistance' => 'operation.reclaim_limit']]],
    'not Swamp' => [['kind' => 'legacy.unregistered']],
    'cancel requested' => [['cancel_requested_at' => '2026-10-30 08:00:00']],
]);

it('refuses resume without serving-Node access or a valid empty request', function (): void {
    [, $id] = executorAdmission($this);
    $operation = Operation::findOrFail($id);
    $operation->update(['status' => 'waiting', 'attempts' => 5, 'state' => ['executor_assistance' => ['error_code' => 'operation.reclaim_limit']]]);
    $before = $operation->getRawOriginal();
    $this->withoutHeader('Idempotency-Key')->postJson("/api/v1/operations/{$id}/resume", ['attempts' => 0])->assertUnprocessable();
    $stranger = executorNode('stranger', '10.44.0.8');
    $this->withServerVariables(['REMOTE_ADDR' => $stranger->wireguard_ip])->postJson("/api/v1/operations/{$id}/resume", [], options: JSON_FORCE_OBJECT)
        ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])->postJson("/api/v1/operations/{$id}/resume", [], options: JSON_FORCE_OBJECT)
        ->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
    expect($operation->refresh()->getRawOriginal())->toBe($before);
});

it('records keyed output once and projects one repeatable completion', function (): void {
    [, $id] = executorAdmission($this);
    asExecutor($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-1'])->assertOk();
    $path = "/api/v1/operation-executor/{$id}";
    $event = ['worker' => 'run-1', 'key' => 'start', 'name' => 'effect', 'status' => 'running', 'output' => "Apply proof\n"];
    $this->postJson($path.'/step', $event)->assertOk();
    $this->postJson($path.'/step', $event)->assertOk();
    $this->postJson($path.'/step', [...$event, 'output' => 'different'])->assertConflict();
    expect(Operation::findOrFail($id)->steps()->sole()->output)->toBe("Apply proof\n");
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(['backend' => 'active']);
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson($path.'/run', executorRunInput($id))->assertOk();
    $this->postJson($path.'/step', [...$event, 'key' => 'finish', 'status' => 'succeeded', 'output' => "Active\n", 'result' => ['backend' => 'active']])->assertOk();
    $completion = ['worker' => 'run-1', 'status' => 'succeeded', 'result' => ['backend' => 'active']];
    $this->postJson($path.'/complete', $completion)->assertOk()->assertJsonPath('data.status', 'succeeded');
    $this->postJson($path.'/complete', $completion)->assertOk();
    expect(Operation::findOrFail($id)->lock_key)->toBeNull();
    $this->postJson($path.'/complete', [...$completion, 'result' => ['backend' => 'inactive']])->assertConflict();
});

it('replays a named agent receipt after a lost reply without running twice', function (): void {
    [$node, $id] = executorAdmission($this);
    asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $target, string $name, array $input) => $target->id === $node->id && $name === 'firewall.rule')->andReturn(['backend' => 'active']);
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-1'])->assertOk();
    $input = executorRunInput($id);
    $this->postJson("/api/v1/operation-executor/{$id}/run", $input)->assertOk()->assertJsonPath('data.backend', 'active');
    $this->postJson("/api/v1/operation-executor/{$id}/run", $input)->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/run", [...$input, 'name' => 'firewall.inspect'])->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
});

it('retains a named invocation identity before an uncertain transport response', function (): void {
    [$node, $id] = executorAdmission($this);
    asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andThrow(new RuntimeException('reply lost'));
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-1'])->assertOk();
    $input = executorRunInput($id);
    $this->postJson("/api/v1/operation-executor/{$id}/run", $input)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    $this->postJson("/api/v1/operation-executor/{$id}/run", [...$input, 'input' => [...$input['input'], 'name' => 'different']])->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
});

it('projects queued cancellation without Node work', function (): void {
    [, $id] = executorAdmission($this);
    app(CancelOperationAction::class)->execute(Operation::findOrFail($id));
    expect(Operation::findOrFail($id)->status)->toBe(OperationStatus::Cancelled);
});

it('leaves Swamp operations alone when PHP jobs and the recovery sweep run', function (): void {
    [, $id] = executorAdmission($this);
    enable_operation_queue();
    app(OperationRunner::class)->run($id);
    expect(Operation::findOrFail($id)->status)->toBe(OperationStatus::Queued);
    $executor = app(SwampOperationExecutor::class);
    asExecutor($this);
    $executor->claim('test.remote', 'run-1', config()->integer('dependency_detection.services_node_id'));
    app(OperationSweeper::class)->sweep();
    expect(Operation::findOrFail($id)->status)->toBe(OperationStatus::Running);
    app(OperationDispatcher::class)->dispatch(Operation::findOrFail($id));
    $this->assertDatabaseCount('jobs', 0);
});

it('refuses execution when the Services identity is unset', function (): void {
    [, $id] = executorAdmission($this);
    asExecutor($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-1'])->assertOk();
    config(['dependency_detection.services_node_id' => null]);
    $this->postJson("/api/v1/operation-executor/{$id}/run", executorRunInput($id))
        ->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
});

it('refuses a caller target, unlisted commands, malformed input and changed intent before dispatch', function (string $case): void {
    [, $id] = executorAdmission($this);
    $services = asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-1'])->assertOk();
    $data = executorRunInput($id);
    $data = match ($case) {
        'target' => [...$data, 'node_id' => $services->id],
        'input target' => [...$data, 'input' => [...$data['input'], 'node_id' => $services->id]],
        'unlisted agent' => [...$data, 'name' => 'gateway.switch-release'],
        'unlisted program' => [...$data, 'transport' => 'remote_program', 'name' => 'gateway.task-workspace-owner'],
        'schema type' => [...$data, 'input' => [...$data['input'], 'port' => 65431]],
        'schema extra' => [...$data, 'input' => [...$data['input'], 'argv' => ['rm']]],
        'changed intent' => [...$data, 'input' => [...$data['input'], 'action' => 'allow']],
        'wrong verb' => [...$data, 'input' => [...$data['input'], 'operation' => 'remove']],
    };
    $this->postJson("/api/v1/operation-executor/{$id}/run", $data)
        ->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect(Operation::findOrFail($id)->state['executor_runs'] ?? [])->toBe([]);
})->with(['target', 'input target', 'unlisted agent', 'unlisted program', 'schema type', 'schema extra', 'changed intent', 'wrong verb']);

it('refuses bare completion and fabricated successful steps', function (): void {
    [, $id] = executorAdmission($this);
    asExecutor($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-1'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/complete", ['worker' => 'run-1', 'status' => 'succeeded', 'result' => ['backend' => 'active']])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson("/api/v1/operation-executor/{$id}/step", ['worker' => 'run-1', 'key' => 'fake', 'name' => 'effect', 'status' => 'succeeded', 'result' => ['backend' => 'active']])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    expect(Operation::findOrFail($id)->status)->toBe(OperationStatus::Running);
});

it('does not project success from an inactive, wrong or refused Node receipt', function (array $result): void {
    [, $id] = executorAdmission($this);
    asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andReturn($result);
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-1'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/run", executorRunInput($id))->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/step", ['worker' => 'run-1', 'key' => 'fake', 'name' => 'effect', 'status' => 'succeeded', 'result' => ['backend' => 'active']])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
})->with([
    'inactive' => [['backend' => 'inactive']],
    'wrong effect' => [['backend' => 'absent']],
    'refused' => [['backend' => 'active', 'error_code' => 'firewall.rule_collision']],
]);

it('drops undeclared result fields and redacts step output before persistence', function (): void {
    [, $id] = executorAdmission($this);
    asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(['backend' => 'active', 'token' => 'run-secret-value', 'log' => 'raw-log-secret', 'nested' => ['password' => 'nested-secret']]);
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-1'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/run", executorRunInput($id))->assertOk()->assertExactJsonStructure(['data' => ['backend'], 'meta' => ['request_id']]);
    $this->postJson("/api/v1/operation-executor/{$id}/step", ['worker' => 'run-1', 'key' => 'done', 'name' => 'effect', 'status' => 'succeeded',
        'result' => ['backend' => 'active', 'token' => 'step-secret-value'], 'output' => "GITHUB_TOKEN=output-secret-value\nAuthorization: Bearer bearer-secret-value\n"])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/complete", ['worker' => 'run-1', 'status' => 'succeeded', 'result' => ['backend' => 'active', 'password' => 'completion-secret-value']])->assertOk();
    $operation = Operation::findOrFail($id);
    $stored = json_encode([$operation->state, $operation->result, $operation->steps()->get()->toArray()], JSON_THROW_ON_ERROR);
    foreach (['run-secret-value', 'raw-log-secret', 'nested-secret', 'step-secret-value', 'output-secret-value', 'bearer-secret-value', 'completion-secret-value'] as $secret) {
        expect($stored)->not->toContain($secret);
    }
    expect($operation->result)->toBe(['backend' => 'active']);
    expect($operation->steps()->sole()->output)->toContain('[REDACTED]');
});

it('redacts retained result fields while keeping stable failure codes', function (): void {
    [, $id] = executorAdmission($this);
    asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(['error_code' => 'token=run-secret-value', 'backend' => ['password' => 'nested-secret']]);
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-1'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/run", executorRunInput($id))->assertOk()->assertJsonPath('data.error_code', 'token=[REDACTED]')->assertJsonMissingPath('data.backend');
    $this->postJson("/api/v1/operation-executor/{$id}/step", ['worker' => 'run-1', 'key' => 'failed', 'name' => 'effect', 'status' => 'failed',
        'result' => ['error_code' => 'password=step-result-secret'], 'error_code' => 'firewall.command_failed'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/complete", ['worker' => 'run-1', 'status' => 'failed',
        'result' => ['error_code' => 'password=completion-result-secret'], 'error_code' => 'firewall.command_failed'])->assertOk()->assertJsonPath('data.error_code', 'firewall.command_failed');
    $operation = Operation::findOrFail($id);
    $stored = json_encode([$operation->toArray(), $operation->steps()->get()->toArray()], JSON_THROW_ON_ERROR);
    foreach (['run-secret-value', 'nested-secret', 'step-result-secret', 'step-error-secret', 'completion-result-secret', 'completion-error-secret'] as $secret) {
        expect($stored)->not->toContain($secret);
    }
});

it('rejects noncanonical error codes in step and completion requests', function (string $error): void {
    [, $id] = executorAdmission($this);
    asExecutor($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-1'])->assertOk();
    $body = ['worker' => 'run-1', 'status' => 'failed', 'error_code' => $error];
    $this->postJson("/api/v1/operation-executor/{$id}/step", [...$body, 'key' => 'error', 'name' => 'effect'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    $this->postJson("/api/v1/operation-executor/{$id}/complete", $body)
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(Operation::findOrFail($id)->status)->toBe(OperationStatus::Running);
})->with(['token=secret', 'UPPER.code', 'two words', 'line'."\n".'break', 'error/code']);

it('rechecks status and cancellation after preparing transport and before invoking the Node', function (string $change): void {
    [, $id] = executorAdmission($this);
    asExecutor($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'run-1'])->assertOk();
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->bind(AgentCommandRunner::class, function () use ($id, $change, $runner) {
        Operation::query()->whereKey($id)->update($change === 'cancel_requested'
            ? ['cancel_requested_at' => now()] : ['status' => OperationStatus::Cancelled->value]);

        return $runner;
    });
    $response = $this->postJson("/api/v1/operation-executor/{$id}/run", executorRunInput($id));
    if ($change === 'cancel_requested') {
        $response->assertOk()->assertJsonPath('data.error_code', 'operation.cancelled');
        // A saved refusal stays refused on retry, without another Node command.
        $this->postJson("/api/v1/operation-executor/{$id}/run", executorRunInput($id))->assertOk()->assertJsonPath('data.error_code', 'operation.cancelled');
    } else {
        $response->assertConflict()->assertJsonPath('error.code', 'operation.lease_lost');
    }
})->with(['cancel_requested', 'terminal']);

it('allows explicitly configured workers only on executor routes while preserving Gateway access', function (): void {
    [, $id] = executorAdmission($this);
    $services = asExecutor($this);
    $worker = executorNode('worker', '10.44.0.12');
    config(['operation_executor.worker_node_ids' => [$worker->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $worker->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'worker-12'])->assertForbidden();
    $worker->accessibleNodes()->attach(Node::query()->where('name', 'gateway')->sole());
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'worker-12'])->assertOk()->assertJsonPath('data.id', $id);
    $this->postJson('/api/v1/projects/1/dependency-read-token')->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'services'])->assertOk();
    config(['operation_executor.worker_node_ids' => []]);
    $this->withServerVariables(['REMOTE_ADDR' => $worker->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'worker-12'])->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
});

it('binds a lease to its authenticated peer even when another worker reuses its worker string', function (): void {
    [, $id] = executorAdmission($this);
    $services = asExecutor($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'shared-worker'])->assertOk()->assertJsonPath('data.id', $id);
    $other = executorNode('other-worker', '10.44.0.9');
    $other->accessibleNodes()->attach(Node::query()->where('name', 'gateway')->sole());
    config(['operation_executor.worker_node_ids' => [$other->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'shared-worker'])->assertOk()->assertJsonPath('data', null);
    $path = '/api/v1/operation-executor/'.$id;
    foreach (['heartbeat' => ['worker' => 'shared-worker'],
        'run' => executorRunInput($id, 'shared-worker'),
        'step' => ['worker' => 'shared-worker', 'key' => 'event', 'name' => 'effect', 'status' => 'running'],
        'complete' => ['worker' => 'shared-worker', 'status' => 'failed', 'error_code' => 'test.failed'],
    ] as $method => $body) {
        $this->postJson($path.'/'.$method, $body)->assertConflict()->assertJsonPath('error.code', 'operation.lease_lost');
    }
    expect(Operation::findOrFail($id)->state['executor_lease_node_id'])->toBe($services->id);
    $this->travel(121)->seconds();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.remote', 'worker' => 'shared-worker'])->assertOk()->assertJsonPath('data.id', $id);
    expect(Operation::findOrFail($id)->state['executor_lease_node_id'])->toBe($other->id)->and(Operation::findOrFail($id)->attempts)->toBe(2);
    $completion = ['worker' => 'shared-worker', 'status' => 'failed', 'error_code' => 'test.failed'];
    $this->postJson($path.'/complete', $completion)->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->postJson($path.'/heartbeat', ['worker' => 'shared-worker'])->assertConflict()->assertJsonPath('error.code', 'operation.lease_lost');
    $this->postJson($path.'/complete', $completion)->assertConflict()->assertJsonPath('error.code', 'operation.completion_conflict');
});
