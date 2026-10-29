<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationDispatcher;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationSweeper;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\Queue;

function executorNode(string $name, string $ip): Node
{
    $node = Node::query()->create(['name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20', 'public_ssh_port' => 22, 'user' => 'orbit', 'wireguard_ip' => $ip]);
    $node->accessibleNodes()->attach($node);

    return $node;
}

function firewallAdmission($test, string $verb = 'allow'): array
{
    $node = executorNode('dev', '10.44.0.3');
    $gateway = executorNode('gateway', '10.44.0.1');
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node->accessibleNodes()->attach($gateway);
    $test->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $response = $test->withHeader('Idempotency-Key', 'allow-proof')->postJson("/api/v1/nodes/{$node->id}/firewall-rules/{$verb}", [
        'name' => 'proof', 'source' => '192.0.2.1/32', 'port' => '65431',
    ])->assertAccepted();

    return [$node, $response->json('data.id')];
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
        'input' => [...$input, 'node_id' => $operation->node_id, 'operation' => $operation->kind === 'firewall.remove' ? 'remove' : 'converge']];
}

function recordExecutorProof($test, int $id, string $backend = 'active', string $worker = 'run-1'): void
{
    $step = $backend === 'absent' ? 'ufw-remove' : 'ufw-converge';
    $test->postJson("/api/v1/operation-executor/{$id}/run", executorRunInput($id, $worker))->assertOk();
    $test->postJson("/api/v1/operation-executor/{$id}/step", [
        'worker' => $worker, 'key' => 'finish', 'name' => $step, 'status' => 'succeeded', 'result' => ['backend' => $backend],
    ])->assertOk();
}

it('records intent and a keyed Operation without PHP dispatch or queue configuration', function (): void {
    Queue::fake();
    [$node, $id] = firewallAdmission($this);
    $this->postJson("/api/v1/nodes/{$node->id}/firewall-rules/allow", ['name' => 'proof', 'source' => '192.0.2.1/32', 'port' => '65431'])
        ->assertAccepted()->assertJsonPath('data.id', $id);
    expect(Operation::query()->count())->toBe(1);
    expect(FirewallRule::query()->sole()->status)->toBe(LifecycleStatus::Provisioning);
    expect(Operation::query()->sole()->status)->toBe(OperationStatus::Queued);
    Queue::assertNothingPushed();
});

it('restricts claims to Services and to registered Swamp kinds', function (): void {
    firewallAdmission($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])
        ->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
    asExecutor($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'unregistered.kind', 'worker' => 'run-1'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'operation.executor_invalid');
});

it('replays a claim and fences the previous worker after lease expiry', function (): void {
    [, $id] = firewallAdmission($this);
    asExecutor($this);
    foreach (range(1, 2) as $_) {
        $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])
            ->assertOk()->assertJsonPath('data.id', $id);
    }
    expect(Operation::findOrFail($id)->attempts)->toBe(1);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-2'])->assertOk()->assertJsonPath('data', null);
    $this->travel(121)->seconds();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-2'])->assertOk()->assertJsonPath('data.id', $id);
    $this->postJson("/api/v1/operation-executor/{$id}/heartbeat", ['worker' => 'run-1'])->assertConflict()->assertJsonPath('error.code', 'operation.lease_lost');
    $this->postJson("/api/v1/operation-executor/{$id}/heartbeat", ['worker' => 'run-2'])->assertOk();
});

it('records keyed output once and projects one repeatable completion', function (): void {
    [, $id] = firewallAdmission($this);
    asExecutor($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])->assertOk();
    $path = "/api/v1/operation-executor/{$id}";
    $event = ['worker' => 'run-1', 'key' => 'start', 'name' => 'ufw-converge', 'status' => 'running', 'output' => "Apply proof\n"];
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
    expect(FirewallRule::query()->sole()->status)->toBe(LifecycleStatus::Active);
    expect(Operation::findOrFail($id)->lock_key)->toBeNull();
    $this->postJson($path.'/complete', [...$completion, 'result' => ['backend' => 'inactive']])->assertConflict();
});

it('projects proven allow and deny receipts using their admitted action', function (string $verb): void {
    [$node, $id] = firewallAdmission($this, $verb);
    asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $target, string $name, array $input): bool => $target->id === $node->id && $name === 'firewall.rule' && $input['action'] === $verb)
        ->andReturn(['backend' => 'active']);
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.'.$verb, 'worker' => 'run-1'])->assertOk();
    recordExecutorProof($this, $id);
    $this->postJson("/api/v1/operation-executor/{$id}/complete", ['worker' => 'run-1', 'status' => 'succeeded', 'result' => ['backend' => 'active']])
        ->assertOk()->assertJsonPath('data.status', 'succeeded');
    expect(FirewallRule::query()->sole()->status)->toBe(LifecycleStatus::Active);
})->with(['allow', 'deny']);

it('replays a named agent receipt after a lost reply without running twice', function (): void {
    [$node, $id] = firewallAdmission($this);
    asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $target, string $name, array $input) => $target->id === $node->id && $name === 'firewall.rule')->andReturn(['backend' => 'active']);
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])->assertOk();
    $input = executorRunInput($id);
    $this->postJson("/api/v1/operation-executor/{$id}/run", $input)->assertOk()->assertJsonPath('data.backend', 'active');
    $this->postJson("/api/v1/operation-executor/{$id}/run", $input)->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/run", [...$input, 'name' => 'firewall.inspect'])->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
});

it('retains a named invocation identity before an uncertain transport response', function (): void {
    [$node, $id] = firewallAdmission($this);
    asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andThrow(new RuntimeException('reply lost'));
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])->assertOk();
    $input = executorRunInput($id);
    $this->postJson("/api/v1/operation-executor/{$id}/run", $input)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    $this->postJson("/api/v1/operation-executor/{$id}/run", [...$input, 'input' => [...$input['input'], 'name' => 'different']])->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
});

it('projects queued cancellation without Node work', function (): void {
    [, $id] = firewallAdmission($this);
    app(CancelOperationAction::class)->execute(Operation::findOrFail($id));
    expect(Operation::findOrFail($id)->status)->toBe(OperationStatus::Cancelled);
    expect(FirewallRule::query()->sole()->status)->toBe(LifecycleStatus::Failed);
});

it('replays a keyed remove admission after the rule was deleted', function (): void {
    [$node, $id] = firewallAdmission($this);
    $executor = app(SwampOperationExecutor::class);
    asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(['backend' => 'active']);
    $runner->shouldReceive('run')->once()->andReturn(['backend' => 'absent']);
    app()->instance(AgentCommandRunner::class, $runner);
    $executor->claim('firewall.allow', 'allow', config()->integer('dependency_detection.services_node_id'));
    recordExecutorProof($this, $id, 'active', 'allow');
    $executor->complete(Operation::findOrFail($id), ['worker' => 'allow', 'status' => 'succeeded', 'result' => ['backend' => 'active']], config()->integer('dependency_detection.services_node_id'));
    $this->withHeader('Idempotency-Key', 'remove-proof')->deleteJson("/api/v1/nodes/{$node->id}/firewall-rules/proof")->assertAccepted();
    $remove = $executor->claim('firewall.remove', 'remove', config()->integer('dependency_detection.services_node_id'));
    $this->withoutHeader('Idempotency-Key');
    $this->postJson("/api/v1/operation-executor/{$remove->id}/complete", ['worker' => 'remove', 'status' => 'succeeded', 'result' => ['backend' => 'absent']])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    expect(FirewallRule::query()->count())->toBe(1);
    recordExecutorProof($this, $remove->id, 'absent', 'remove');
    $executor->complete($remove, ['worker' => 'remove', 'status' => 'succeeded', 'result' => ['backend' => 'absent']], config()->integer('dependency_detection.services_node_id'));
    $this->withHeader('Idempotency-Key', 'remove-proof');
    expect(FirewallRule::query()->count())->toBe(0);
    $this->deleteJson("/api/v1/nodes/{$node->id}/firewall-rules/proof")->assertAccepted()->assertJsonPath('data.id', $remove->id);
});

it('leaves Swamp operations alone when PHP jobs and the recovery sweep run', function (): void {
    [, $id] = firewallAdmission($this);
    enable_operation_queue();
    app(OperationRunner::class)->run($id);
    expect(Operation::findOrFail($id)->status)->toBe(OperationStatus::Queued);
    $executor = app(SwampOperationExecutor::class);
    asExecutor($this);
    $executor->claim('firewall.allow', 'run-1', config()->integer('dependency_detection.services_node_id'));
    app(OperationSweeper::class)->sweep();
    expect(Operation::findOrFail($id)->status)->toBe(OperationStatus::Running);
    app(OperationDispatcher::class)->dispatch(Operation::findOrFail($id));
    $this->assertDatabaseCount('jobs', 0);
});

it('refuses execution when the Services identity is unset', function (): void {
    [, $id] = firewallAdmission($this);
    asExecutor($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])->assertOk();
    config(['dependency_detection.services_node_id' => null]);
    $this->postJson("/api/v1/operation-executor/{$id}/run", executorRunInput($id))
        ->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
});

it('refuses a caller target, unlisted commands, malformed input and changed intent before dispatch', function (string $case): void {
    [, $id] = firewallAdmission($this);
    $services = asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])->assertOk();
    $data = executorRunInput($id);
    $data = match ($case) {
        'target' => [...$data, 'node_id' => $services->id],
        'input target' => [...$data, 'input' => [...$data['input'], 'node_id' => $services->id]],
        'unlisted agent' => [...$data, 'name' => 'gateway.switch-release'],
        'unlisted program' => [...$data, 'transport' => 'remote_program', 'name' => 'gateway.task-workspace-owner'],
        'schema type' => [...$data, 'input' => [...$data['input'], 'port' => 65431]],
        'schema extra' => [...$data, 'input' => [...$data['input'], 'argv' => ['rm']]],
        'changed intent' => [...$data, 'input' => [...$data['input'], 'action' => 'deny']],
        'wrong verb' => [...$data, 'input' => [...$data['input'], 'operation' => 'remove']],
    };
    $this->postJson("/api/v1/operation-executor/{$id}/run", $data)
        ->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect(Operation::findOrFail($id)->state['executor_runs'] ?? [])->toBe([]);
})->with(['target', 'input target', 'unlisted agent', 'unlisted program', 'schema type', 'schema extra', 'changed intent', 'wrong verb']);

it('refuses bare completion and fabricated successful steps', function (): void {
    [, $id] = firewallAdmission($this);
    asExecutor($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/complete", ['worker' => 'run-1', 'status' => 'succeeded', 'result' => ['backend' => 'active']])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson("/api/v1/operation-executor/{$id}/step", ['worker' => 'run-1', 'key' => 'fake', 'name' => 'ufw-converge', 'status' => 'succeeded', 'result' => ['backend' => 'active']])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    expect(FirewallRule::query()->sole()->status)->toBe(LifecycleStatus::Provisioning);
    expect(Operation::findOrFail($id)->status)->toBe(OperationStatus::Running);
});

it('does not project success from an inactive, wrong or refused Node receipt', function (array $result): void {
    [, $id] = firewallAdmission($this);
    asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andReturn($result);
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/run", executorRunInput($id))->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/step", ['worker' => 'run-1', 'key' => 'fake', 'name' => 'ufw-converge', 'status' => 'succeeded', 'result' => ['backend' => 'active']])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    expect(FirewallRule::query()->sole()->status)->toBe(LifecycleStatus::Provisioning);
})->with([
    'inactive' => [['backend' => 'inactive']],
    'wrong effect' => [['backend' => 'absent']],
    'refused' => [['backend' => 'active', 'error_code' => 'firewall.rule_collision']],
]);

it('drops undeclared run and completion fields and redacts retained fields and step output before persistence', function (): void {
    [, $id] = firewallAdmission($this);
    asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(['backend' => 'active', 'token' => 'run-secret-value', 'log' => 'raw-log-secret', 'nested' => ['password' => 'nested-secret']]);
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/run", executorRunInput($id))->assertOk()->assertExactJsonStructure(['data' => ['backend'], 'meta' => ['request_id']]);
    $this->postJson("/api/v1/operation-executor/{$id}/step", ['worker' => 'run-1', 'key' => 'done', 'name' => 'ufw-converge', 'status' => 'succeeded',
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
    [, $id] = firewallAdmission($this);
    asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(['error_code' => 'token=run-secret-value', 'backend' => ['password' => 'nested-secret']]);
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/run", executorRunInput($id))->assertOk()->assertJsonPath('data.error_code', 'token=[REDACTED]')->assertJsonMissingPath('data.backend');
    $this->postJson("/api/v1/operation-executor/{$id}/step", ['worker' => 'run-1', 'key' => 'failed', 'name' => 'ufw-converge', 'status' => 'failed',
        'result' => ['error_code' => 'password=step-result-secret'], 'error_code' => 'firewall.command_failed'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/complete", ['worker' => 'run-1', 'status' => 'failed',
        'result' => ['error_code' => 'password=completion-result-secret'], 'error_code' => 'firewall.command_failed'])->assertOk()->assertJsonPath('data.error_code', 'firewall.command_failed');
    $operation = Operation::findOrFail($id);
    $stored = json_encode([$operation->toArray(), $operation->steps()->get()->toArray(), FirewallRule::query()->sole()->toArray()], JSON_THROW_ON_ERROR);
    foreach (['run-secret-value', 'nested-secret', 'step-result-secret', 'step-error-secret', 'completion-result-secret', 'completion-error-secret'] as $secret) {
        expect($stored)->not->toContain($secret);
    }
});

it('rejects noncanonical error codes in step and completion requests', function (string $error): void {
    [, $id] = firewallAdmission($this);
    asExecutor($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])->assertOk();
    $body = ['worker' => 'run-1', 'status' => 'failed', 'error_code' => $error];
    $this->postJson("/api/v1/operation-executor/{$id}/step", [...$body, 'key' => 'error', 'name' => 'ufw-converge'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    $this->postJson("/api/v1/operation-executor/{$id}/complete", $body)
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(Operation::findOrFail($id)->status)->toBe(OperationStatus::Running);
})->with(['token=secret', 'UPPER.code', 'two words', 'line'."\n".'break', 'error/code']);

it('rechecks status and cancellation after preparing transport and before invoking the Node', function (string $change): void {
    [, $id] = firewallAdmission($this);
    asExecutor($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])->assertOk();
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
    [, $id] = firewallAdmission($this);
    $services = asExecutor($this);
    $worker = executorNode('worker', '10.44.0.12');
    config(['operation_executor.worker_node_ids' => [$worker->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $worker->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'worker-12'])->assertForbidden();
    $worker->accessibleNodes()->attach(Node::query()->where('name', 'gateway')->sole());
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'worker-12'])->assertOk()->assertJsonPath('data.id', $id);
    $this->postJson('/api/v1/projects/1/dependency-read-token')->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.deny', 'worker' => 'services'])->assertOk();
    config(['operation_executor.worker_node_ids' => []]);
    $this->withServerVariables(['REMOTE_ADDR' => $worker->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'worker-12'])->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
});

it('binds a lease to its authenticated peer even when another worker reuses its worker string', function (): void {
    [, $id] = firewallAdmission($this);
    $services = asExecutor($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'shared-worker'])->assertOk()->assertJsonPath('data.id', $id);
    $other = executorNode('other-worker', '10.44.0.9');
    $other->accessibleNodes()->attach(Node::query()->where('name', 'gateway')->sole());
    config(['operation_executor.worker_node_ids' => [$other->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'shared-worker'])->assertOk()->assertJsonPath('data', null);
    $path = '/api/v1/operation-executor/'.$id;
    foreach (['heartbeat' => ['worker' => 'shared-worker'],
        'run' => executorRunInput($id, 'shared-worker'),
        'step' => ['worker' => 'shared-worker', 'key' => 'event', 'name' => 'ufw-converge', 'status' => 'running'],
        'complete' => ['worker' => 'shared-worker', 'status' => 'failed', 'error_code' => 'test.failed'],
    ] as $method => $body) {
        $this->postJson($path.'/'.$method, $body)->assertConflict()->assertJsonPath('error.code', 'operation.lease_lost');
    }
    expect(Operation::findOrFail($id)->state['executor_lease_node_id'])->toBe($services->id);
    $this->travel(121)->seconds();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'shared-worker'])->assertOk()->assertJsonPath('data.id', $id);
    expect(Operation::findOrFail($id)->state['executor_lease_node_id'])->toBe($other->id)->and(Operation::findOrFail($id)->attempts)->toBe(2);
    $completion = ['worker' => 'shared-worker', 'status' => 'failed', 'error_code' => 'test.failed'];
    $this->postJson($path.'/complete', $completion)->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->postJson($path.'/heartbeat', ['worker' => 'shared-worker'])->assertConflict()->assertJsonPath('error.code', 'operation.lease_lost');
    $this->postJson($path.'/complete', $completion)->assertConflict()->assertJsonPath('error.code', 'operation.completion_conflict');
});
