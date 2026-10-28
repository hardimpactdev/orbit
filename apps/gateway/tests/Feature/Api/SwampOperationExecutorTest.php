<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationDispatcher;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationSweeper;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramRunner;
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

function firewallAdmission($test): array
{
    $node = executorNode('dev', '10.44.0.3');
    $gateway = executorNode('gateway', '10.44.0.1');
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node->accessibleNodes()->attach($gateway);
    $test->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $response = $test->withHeader('Idempotency-Key', 'allow-proof')->postJson("/api/v1/nodes/{$node->id}/firewall-rules/allow", [
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
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.deploy', 'worker' => 'run-1'])
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
    $this->postJson($path.'/step', [...$event, 'key' => 'finish', 'status' => 'succeeded', 'output' => "Active\n", 'result' => ['backend' => 'active']])->assertOk();
    $completion = ['worker' => 'run-1', 'status' => 'succeeded', 'result' => ['backend' => 'active']];
    $this->postJson($path.'/complete', $completion)->assertOk()->assertJsonPath('data.status', 'succeeded');
    $this->postJson($path.'/complete', $completion)->assertOk();
    expect(FirewallRule::query()->sole()->status)->toBe(LifecycleStatus::Active);
    expect(Operation::findOrFail($id)->lock_key)->toBeNull();
    $this->postJson($path.'/complete', [...$completion, 'result' => ['backend' => 'inactive']])->assertConflict();
});

it('replays a named agent receipt after a lost reply without running twice', function (): void {
    [$node, $id] = firewallAdmission($this);
    asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $target, string $name, array $input) => $target->id === $node->id && $name === 'firewall.rule')->andReturn(['backend' => 'active']);
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])->assertOk();
    $input = ['worker' => 'run-1', 'key' => 'ufw', 'node_id' => $node->id, 'transport' => 'agent', 'name' => 'firewall.rule', 'input' => ['name' => 'proof']];
    $this->postJson("/api/v1/operation-executor/{$id}/run", $input)->assertOk()->assertJsonPath('data.backend', 'active');
    $this->postJson("/api/v1/operation-executor/{$id}/run", $input)->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/run", [...$input, 'name' => 'firewall.inspect'])->assertConflict();
});

it('retains a named invocation identity before an uncertain transport response', function (): void {
    [$node, $id] = firewallAdmission($this);
    asExecutor($this);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andThrow(new RuntimeException('reply lost'));
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])->assertOk();
    $input = ['worker' => 'run-1', 'key' => 'ufw', 'node_id' => $node->id, 'transport' => 'agent', 'name' => 'firewall.rule', 'input' => ['name' => 'proof']];
    $this->postJson("/api/v1/operation-executor/{$id}/run", $input)->assertStatus(500);
    $this->postJson("/api/v1/operation-executor/{$id}/run", [...$input, 'input' => ['name' => 'different']])->assertConflict();
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
    $executor->complete($executor->claim('firewall.allow', 'allow'), ['worker' => 'allow', 'status' => 'succeeded']);
    $this->withHeader('Idempotency-Key', 'remove-proof')->deleteJson("/api/v1/nodes/{$node->id}/firewall-rules/proof")->assertAccepted();
    $remove = $executor->claim('firewall.remove', 'remove');
    $executor->complete($remove, ['worker' => 'remove', 'status' => 'succeeded']);
    expect(FirewallRule::query()->count())->toBe(0);
    $this->deleteJson("/api/v1/nodes/{$node->id}/firewall-rules/proof")->assertAccepted()->assertJsonPath('data.id', $remove->id);
});

it('leaves Swamp operations alone when PHP jobs and the recovery sweep run', function (): void {
    [, $id] = firewallAdmission($this);
    enable_operation_queue();
    app(OperationRunner::class)->run($id);
    expect(Operation::findOrFail($id)->status)->toBe(OperationStatus::Queued);
    $executor = app(SwampOperationExecutor::class);
    $executor->claim('firewall.allow', 'run-1');
    app(OperationSweeper::class)->sweep();
    expect(Operation::findOrFail($id)->status)->toBe(OperationStatus::Running);
    app(OperationDispatcher::class)->dispatch(Operation::findOrFail($id));
    $this->assertDatabaseCount('jobs', 0);
});

it('runs a named RemoteProgram through the existing catalog and runner', function (): void {
    [$node, $id] = firewallAdmission($this);
    asExecutor($this);
    $runner = Mockery::mock(RemoteProgramRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $target, RemoteProgram $program, array $input): bool => $target->id === $node->id && $program->name === 'gateway.task-workspace-owner' && $input === ['host' => 'dev'])
        ->andReturn(new ProgramResult('inspected', ['dead' => false], "Inspected\n"));
    app()->instance(RemoteProgramRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'run-1'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$id}/run", ['worker' => 'run-1', 'key' => 'inspect', 'node_id' => $node->id,
        'transport' => 'remote_program', 'name' => 'gateway.task-workspace-owner', 'input' => ['host' => 'dev']])
        ->assertOk()->assertJsonPath('data.outcome', 'inspected')->assertJsonPath('data.data.dead', false);
});
