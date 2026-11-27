<?php

declare(strict_types=1);

use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\MachineBus;
use App\Domain\Engine\StepSpec;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Instances\InstanceDestroyEngineFlow;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Routes\RouteDestroyEngineFlow;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A machine bus that keeps the steps the engine sends, each time it sends them. */
final class ReceiptBoundBus implements MachineBus
{
    /** @var list<string> */
    public array $sent = [];

    public function publishOrder(int $node, SignedExecutorOrder $order, ?Closure $claim = null): void
    {
        if ($claim !== null && ! $claim()) {
            return;
        }
        $payload = json_decode((string) base64_decode(explode('.', $order->wire)[0], true), true, flags: JSON_THROW_ON_ERROR);
        $this->sent[] = explode(':', $payload['operation'])[1];
    }

    public function publishStart(int $operation): void {}

    public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void {}

    public function nudge(int $operation, string $id): void {}

    public function putContent(int $node, string $content): array
    {
        return ['sha256' => hash('sha256', $content)];
    }

    public function nodeActions(int $node): array
    {
        return [];
    }

    public function nodeFeatures(int $node): array
    {
        return [];
    }

    public function nodeTargetActions(int $node): array
    {
        return [];
    }

    public function nodeClock(int $node): ?bool
    {
        return null;
    }
}

/** A withdrawal over two VPN Nodes' DNS, published together, and the site withdrawal after both. */
final class ReceiptBoundDefinition implements EngineDefinition
{
    public static ?string $finished = null;

    public function __construct(private int $node) {}

    public function steps(Operation $operation): array
    {
        return [
            new StepSpec('dns-a', $this->node, 'dns.publish_records', (string) $this->node, budgetMs: 120_000, resendMs: 20_000),
            new StepSpec('dns-b', $this->node, 'dns.publish_records', (string) $this->node, budgetMs: 120_000, resendMs: 20_000),
            new StepSpec('withdraw', $this->node, 'route.withdraw_site', (string) $this->node, ['dns-a', 'dns-b'], budgetMs: 600_000, resendMs: 30_000),
        ];
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        return ['step' => $step];
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        throw new LogicException('no Gateway step');
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        self::$finished = $run->failed()?->name ?? 'none';
        $operation->forceFill(['status' => $run->failed() === null ? OperationStatus::Succeeded : OperationStatus::Failed, 'lock_key' => null])->save();
    }
}

beforeEach(function (): void {
    $pair = sodium_crypto_sign_keypair();
    config(['orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey($pair))]);
    $this->bus = new ReceiptBoundBus;
    $this->app->instance(MachineBus::class, $this->bus);
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'vpn', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '10.44.0.1', 'user' => 'orbit',
    ]));
    ReceiptBoundDefinition::$finished = null;
    $this->start = function (string $kind, string $flow, string $type): Operation {
        $this->app->instance($flow, new ReceiptBoundDefinition($this->node->id));
        $operation = Operation::query()->forceCreate(['kind' => $kind, 'status' => OperationStatus::Running, 'target_type' => $type, 'target_id' => 7,
            'node_id' => $this->node->id, 'input' => [], 'lock_key' => OperationStore::lockKey($type, 7)]);
        $operation->forceFill(['state' => ['swamp_run_id' => 'engine-'.$operation->id]])->save();
        app(Engine::class)->start($operation);
        app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);

        return $operation;
    };
    $this->receipt = fn (Operation $operation, string $step, string $action, array $line) => app(Engine::class)->onReceipt([
        'node' => $this->node->id, 'line' => ['operation' => $operation->id.':'.$step, 'action' => $action, 'target' => (string) $this->node->id, ...$line],
    ]);
    $this->status = fn (Operation $operation, string $step): ?string => OperationEngineStep::query()->where('operation_id', $operation->id)
        ->where('name', $step)->value('status');
});

dataset('destroy kinds', [
    'route.destroy' => ['route.destroy', RouteDestroyEngineFlow::class, 'route'],
    'Route-owning instance.destroy' => [InstanceDestroyEngineFlow::Kind, InstanceDestroyEngineFlow::class, 'instance'],
]);

it('is receipt-bound for both removal kinds that withdraw a Route', function (string $kind): void {
    expect(Engine::receiptBound($kind))->toBeTrue();
})->with('destroy kinds');

it('keeps a withdrawal step fenced across a content failure before the journal and past its budget, until a terminal receipt', function (string $kind, string $flow, string $type): void {
    $operation = ($this->start)($kind, $flow, $type);
    ($this->receipt)($operation, 'dns-a', 'dns.publish_records', ['status' => 'applied', 'changes' => 1]);
    ($this->receipt)($operation, 'dns-b', 'dns.publish_records', ['status' => 'applied', 'changes' => 1]);

    // The executor answers blob.* before its journal: another send may still act, so the step stays sent.
    ($this->receipt)($operation, 'withdraw', 'route.withdraw_site', ['status' => 'failed', 'code' => 'blob.fetch_failed']);
    expect(($this->status)($operation, 'withdraw'))->toBe('sent')->and($operation->refresh()->status)->toBe(OperationStatus::Running);

    // No receipt at all: past the step's budget, the target stays fenced and the step is sent again.
    $this->travel(601)->seconds();
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);
    expect(($this->status)($operation, 'withdraw'))->toBe('sent')
        ->and($operation->refresh()->lock_key)->toBe(OperationStore::lockKey($type, 7))
        ->and(array_count_values($this->bus->sent)['withdraw'])->toBe(2);

    ($this->receipt)($operation, 'withdraw', 'route.withdraw_site', ['status' => 'applied', 'changes' => 2]);
    expect(ReceiptBoundDefinition::$finished)->toBe('none');
})->with('destroy kinds');

it('settles a failed DNS step only once every other published DNS order has its terminal receipt', function (string $kind, string $flow, string $type): void {
    $operation = ($this->start)($kind, $flow, $type);
    expect($this->bus->sent)->toBe(['dns-a', 'dns-b']);

    ($this->receipt)($operation, 'dns-a', 'dns.publish_records', ['status' => 'failed', 'code' => 'owned_revision_conflict']);
    expect(ReceiptBoundDefinition::$finished)->toBeNull()
        ->and($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->lock_key)->toBe(OperationStore::lockKey($type, 7));

    // The outstanding order is sent again at its deadline; nothing new starts.
    $this->travel(21)->seconds();
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);
    expect($this->bus->sent)->toBe(['dns-a', 'dns-b', 'dns-b']);

    ($this->receipt)($operation, 'dns-b', 'dns.publish_records', ['status' => 'applied', 'changes' => 1]);
    expect(ReceiptBoundDefinition::$finished)->toBe('dns-a')->and($this->bus->sent)->not->toContain('withdraw');
})->with('destroy kinds');
