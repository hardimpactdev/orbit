<?php

declare(strict_types=1);

use App\Domain\Engine\Engine;
use App\Domain\Engine\MachineBus;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampConnection;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/** A machine bus that keeps what the engine publishes, and can lose one publish. */
final class FakeMachineBus implements MachineBus
{
    /** @var list<array{node: int, order: SignedExecutorOrder}> */
    public array $orders = [];

    /** @var list<int> */
    public array $starts = [];

    /** @var list<array{operation: int, step: string, sends: int, at: int}> */
    public array $deadlines = [];

    /** @var list<string> */
    public array $actions = ['ufw.ensure_rule'];

    /** @var list<string> */
    public array $nudges = [];

    public int $failNext = 0;

    public function publishOrder(int $node, SignedExecutorOrder $order): void
    {
        if ($this->failNext > 0) {
            $this->failNext--;
            throw new RuntimeException('engine.publish_failed');
        }
        $this->orders[] = ['node' => $node, 'order' => $order];
    }

    public function publishStart(int $operation): void
    {
        $this->starts[] = $operation;
    }

    public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void
    {
        $this->deadlines[] = ['operation' => $operation, 'step' => $step, 'sends' => $sends, 'at' => $atMs];
    }

    public function nudge(int $operation, string $id): void
    {
        $this->nudges[] = "{$id}-{$operation}";
    }

    public function putContent(int $node, string $content): array
    {
        return ['sha256' => hash('sha256', $content)];
    }

    public function nodeActions(int $node): array
    {
        return $this->actions;
    }

    public function nodeClock(int $node): ?bool
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function payload(int $index): array
    {
        $order = $this->orders[$index]['order'] ?? throw new RuntimeException("no order {$index}");

        return json_decode((string) base64_decode(explode('.', $order->wire)[0], true), true, flags: JSON_THROW_ON_ERROR);
    }
}

afterEach(fn () => app(SwampConnection::class)->release());

beforeEach(function (): void {
    Queue::fake();
    $pair = sodium_crypto_sign_keypair();
    config(['orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey($pair)), 'orbit.engine.kinds' => ['firewall.allow', 'firewall.remove'],
        'swamp.run_token' => 'test-token', 'swamp.read_token' => 'read-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $this->bus = new FakeMachineBus;
    $this->app->instance(MachineBus::class, $this->bus);
    $this->swampStarts = 0;
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $message): void {
        $this->swampStarts++;
        $this->lastRequest = $message;
    });
    $socket->shouldReceive('receive')->andReturnUsing(fn (): array => [[
        'type' => 'event', 'id' => $this->lastRequest['id'],
        'event' => ['kind' => 'started', 'workflowName' => 'operation-firewall-direct', 'runId' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ]]);
    $this->app->instance(WebSocketClient::class, $socket);
    $this->caller = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'operator', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit',
    ]));
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'app-dev', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'target-public-key',
    ]));
    $this->caller->roles()->create(['role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);
    $this->caller->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->allow = function (): Operation {
        $response = $this->postJson('/api/v1/nodes/'.$this->node->id.'/firewall-rules/allow', ['port' => '443', 'protocol' => 'tcp', 'source' => '192.0.2.1']);
        $response->assertStatus(202);
        $operation = Operation::query()->findOrFail($response->json('data.id'));
        app(StartSwampRun::class)->start($operation);

        return $operation->refresh();
    };
    $this->receipt = fn (Operation $operation, array $line) => app(Engine::class)->onReceipt([
        'node' => $this->node->id, 'line' => ['operation' => $operation->id.':ensure-0', ...$line],
    ]);
});

it('starts a firewall allow on the state machine, signs one ufw.ensure_rule step and ends through the projection', function (): void {
    $operation = ($this->allow)();

    expect($operation->state['swamp_run_id'])->toBe('engine-'.$operation->id)
        ->and($this->bus->starts)->toBe([$operation->id])
        ->and($this->swampStarts)->toBe(0)
        ->and(OperationEngineStep::query()->where('operation_id', $operation->id)->pluck('name')->all())->toBe(['ensure-0']);

    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    $payload = $this->bus->payload(0);
    expect($this->bus->orders[0]['node'])->toBe($this->node->id)
        ->and($payload['action'])->toBe('ufw.ensure_rule')
        ->and($payload['operation'])->toBe($operation->id.':ensure-0')
        ->and($payload['target'])->toBe((string) $this->node->id)
        ->and($payload['input'])->toMatchArray(['action' => 'allow', 'port' => '443', 'protocol' => 'tcp', 'source' => '192.0.2.1', 'ensure' => 'present', 'retain' => false])
        ->and($this->bus->deadlines)->toHaveCount(1)
        ->and($operation->refresh()->state['native_progress']['steps']['ensure-0']['status'])->toBe('running');

    ($this->receipt)($operation, ['status' => 'applied', 'changes' => 1]);

    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result)->toBe(['backend' => 'active'])
        ->and($operation->state['native_progress']['steps']['ensure-0']['status'])->toBe('succeeded')
        ->and(FirewallRule::query()->sole()->status)->toBe(LifecycleStatus::Active);
});

it('records a rule that needed no change as already applied', function (): void {
    $operation = ($this->allow)();
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    ($this->receipt)($operation, ['status' => 'applied', 'changes' => 0, 'repeat' => false]);

    expect($operation->refresh()->result)->toBe(['backend' => 'active', 'skipped' => 'firewall.rule_exists']);
});

it('fails the Operation with the step and code the executor reported', function (): void {
    $operation = ($this->allow)();
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    ($this->receipt)($operation, ['status' => 'failed', 'code' => 'firewall.verify_failed']);

    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('firewall.verify_failed')
        ->and($operation->failed_step)->toBe('ensure-0')
        ->and(FirewallRule::query()->sole()->status)->toBe(LifecycleStatus::Failed);
});

it('sends a new order for an order that expired while waiting, and ignores a replayed one', function (): void {
    $operation = ($this->allow)();
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    ($this->receipt)($operation, ['status' => 'refused', 'reason' => 'replayed_nonce']);
    expect($this->bus->orders)->toHaveCount(1);

    ($this->receipt)($operation, ['status' => 'refused', 'reason' => 'expired']);
    expect($this->bus->orders)->toHaveCount(2)
        ->and($this->bus->payload(1)['nonce'])->not->toBe($this->bus->payload(0)['nonce'])
        ->and($operation->refresh()->status)->toBe(OperationStatus::Running);

    ($this->receipt)($operation, ['status' => 'failed', 'code' => 'executor.order_expired']);
    expect($this->bus->orders)->toHaveCount(3)
        ->and($operation->refresh()->status)->toBe(OperationStatus::Running);
});

it('puts a step whose publish failed back to pending and throws, so the event is delivered again', function (): void {
    $operation = ($this->allow)();
    $this->bus->failNext = 1;

    expect(fn () => app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]))->toThrow(RuntimeException::class);
    expect(OperationEngineStep::query()->sole()->status)->toBe('pending');

    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    expect($this->bus->orders)->toHaveCount(1)
        ->and(OperationEngineStep::query()->sole()->status)->toBe('sent');
});

it('re-sends an overdue step at its deadline and fails it when its budget ends', function (): void {
    $operation = ($this->allow)();
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    $this->travel(11)->seconds();
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id, 'step' => 'ensure-0']);
    expect($this->bus->orders)->toHaveCount(2);

    $this->travel(120)->seconds();
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id, 'step' => 'ensure-0']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('engine.step_budget_exceeded');
});

it('keeps a kind on Swamp while its Node has not reported the primitive', function (): void {
    $this->bus->actions = [];
    $operation = ($this->allow)();

    expect($operation->state['swamp_run_id'])->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844')
        ->and($this->swampStarts)->toBeGreaterThan(0)
        ->and($this->bus->starts)->toBe([])
        ->and(OperationEngineStep::query()->count())->toBe(0);
});

it('ends a cancelled engine Operation at its next transition without sending more', function (): void {
    $operation = ($this->allow)();
    $operation->forceFill(['cancel_requested_at' => now()])->save();
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);

    expect($this->bus->orders)->toBe([])
        ->and($operation->refresh()->status)->toBe(OperationStatus::Cancelled);
});

it('signs the input the executor\'s ufw.ensure_rule reads, as the shared contract fixture states', function (): void {
    $fixture = json_decode((string) file_get_contents(base_path('../agent/contract/fixtures/ufw-ensure-rule-input.json')), true, flags: JSON_THROW_ON_ERROR);
    $response = $this->postJson('/api/v1/nodes/'.$this->node->id.'/firewall-rules/allow', $fixture['intent']);
    $response->assertStatus(202);
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);

    expect($this->bus->payload(0)['input'])->toBe($fixture['input']);
});

it('records a remove that found no line as already removed', function (): void {
    $allow = ($this->allow)();
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $allow->id]);
    ($this->receipt)($allow, ['status' => 'applied', 'changes' => 1]);

    $response = $this->postJson('/api/v1/nodes/'.$this->node->id.'/firewall-rules/remove', ['port' => '443', 'protocol' => 'tcp', 'source' => '192.0.2.1']);
    $response->assertStatus(202);
    $remove = Operation::query()->findOrFail($response->json('data.id'));
    app(StartSwampRun::class)->start($remove);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $remove->id]);
    expect($this->bus->payload(1)['input']['ensure'])->toBe('absent');
    ($this->receipt)($remove, ['status' => 'applied', 'changes' => 0]);

    expect($remove->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($remove->result)->toBe(['backend' => 'absent', 'skipped' => 'firewall.rule_absent']);
});

it('keeps what happened on the Node when a cancel came after the step ended', function (): void {
    $operation = ($this->allow)();
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    $operation->forceFill(['cancel_requested_at' => now()])->save();
    ($this->receipt)($operation, ['status' => 'applied', 'changes' => 1]);

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and(FirewallRule::query()->sole()->status)->toBe(LifecycleStatus::Active);
});

it('records the first result that a replayed order\'s refusal carries', function (): void {
    $operation = ($this->allow)();
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    ($this->receipt)($operation, ['status' => 'refused', 'reason' => 'replayed_nonce', 'first' => ['status' => 'applied', 'changes' => 1]]);

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
});

it('records the end again when its projection failed the first time', function (): void {
    $operation = ($this->allow)();
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    $rule = FirewallRule::query()->sole();
    $attributes = $rule->getAttributes();
    $rule->delete();

    expect(fn () => ($this->receipt)($operation, ['status' => 'applied', 'changes' => 1]))->toThrow(ModelNotFoundException::class);
    expect($operation->refresh()->status)->toBe(OperationStatus::Running);

    FirewallRule::query()->insert($attributes);
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id, 'step' => 'ensure-0']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
});

it('fails a step whose order cannot be made instead of trying it again', function (): void {
    $operation = ($this->allow)();
    $input = $operation->input;
    $input['firewall']['rule']['port'] = null;
    $operation->forceFill(['input' => $input])->save();
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);

    expect($this->bus->orders)->toBe([])
        ->and($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('engine.order_invalid');
});

it('nudges an Operation whose start was lost, and leaves one that waits on its own deadline', function (): void {
    $operation = ($this->allow)();
    app(Engine::class)->nudgeIfStalled($operation);
    expect($this->bus->nudges)->toHaveCount(1);

    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    app(Engine::class)->nudgeIfStalled($operation);
    expect($this->bus->nudges)->toHaveCount(1);

    $this->travel(41)->seconds();
    app(Engine::class)->nudgeIfStalled($operation);
    expect($this->bus->nudges)->toHaveCount(2);
});

it('cancels through a nudge of its own, never the start event\'s message id', function (): void {
    $operation = ($this->allow)();
    app(Engine::class)->cancel($operation);

    expect($this->bus->nudges)->toHaveCount(1)
        ->and($this->bus->nudges[0])->toStartWith('cancel-');
});
