<?php

declare(strict_types=1);

use App\Data\Operations\OperationData;
use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineWait;
use App\Domain\Engine\MachineBus;
use App\Domain\Engine\StepSpec;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Routes\RouteDestroyEngineFlow;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A machine bus that keeps the steps the engine sends and the deadlines it schedules. */
final class WaitBus implements MachineBus
{
    /** @var list<string> */
    public array $sent = [];

    /** @var list<array{0: string, 1: int}> */
    public array $deadlines = [];

    public function publishOrder(int $node, SignedExecutorOrder $order, ?Closure $claim = null): void
    {
        if ($claim !== null && ! $claim()) {
            return;
        }
        $payload = json_decode((string) base64_decode(explode('.', $order->wire)[0], true), true, flags: JSON_THROW_ON_ERROR);
        $this->sent[] = explode(':', $payload['operation'])[1];
    }

    public function publishStart(int $operation): void {}

    public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void
    {
        $this->deadlines[] = [$step, $atMs];
    }

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

/** route.destroy's DNS step, its real `grace` (RouteDestroyEngineFlow), and the withdrawal after it. */
final readonly class WaitDefinition implements EngineDefinition, EngineWait
{
    public function __construct(private int $node, private RouteDestroyEngineFlow $flow) {}

    public function steps(Operation $operation): array
    {
        return [
            new StepSpec('dns-0', $this->node, 'dns.publish_records', (string) $this->node, budgetMs: 120_000, resendMs: 20_000),
            new StepSpec('grace', 0, 'route.dns_grace', 'gateway', ['dns-0'], budgetMs: 60_000),
            new StepSpec('withdraw-3', $this->node, 'route.withdraw_site', (string) $this->node, ['grace'], budgetMs: 600_000, resendMs: 30_000),
        ];
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        return ['step' => $step];
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        return $this->flow->local($operation, $step, $run);
    }

    public function waitUntilMs(Operation $operation, string $step, EngineRun $run): ?int
    {
        return $this->flow->waitUntilMs($operation, $step, $run);
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $status = match (true) {
            $run->failed() !== null => OperationStatus::Failed,
            $run->succeeded() => OperationStatus::Succeeded,
            default => OperationStatus::Cancelled,
        };
        $operation->forceFill(['status' => $status, 'lock_key' => null])->save();
    }
}

beforeEach(function (): void {
    $pair = sodium_crypto_sign_keypair();
    config(['orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey($pair))]);
    $this->bus = new WaitBus;
    $this->app->instance(MachineBus::class, $this->bus);
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'vpn', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '10.44.0.1', 'user' => 'orbit',
    ]));
    $this->node = $node->id;
    $this->app->instance(RouteDestroyEngineFlow::class, new WaitDefinition($node->id, $this->app->make(RouteDestroyEngineFlow::class)));
    $this->freezeTime();
    $this->operation = Operation::query()->forceCreate(['kind' => 'route.destroy', 'status' => OperationStatus::Running, 'target_type' => 'route', 'target_id' => 7,
        'node_id' => $node->id, 'input' => [], 'lock_key' => OperationStore::lockKey('route', 7)]);
    $this->operation->forceFill(['state' => ['swamp_run_id' => 'engine-'.$this->operation->id]])->save();
    app(Engine::class)->start($this->operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $this->operation->id]);
    $this->dnsReceipt = fn () => app(Engine::class)->onReceipt(['node' => $this->node, 'line' => [
        'operation' => $this->operation->id.':dns-0', 'action' => 'dns.publish_records', 'target' => (string) $this->node, 'status' => 'applied', 'changes' => 1,
    ]]);
    $this->deadline = fn () => app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $this->operation->id]);
    // What `operation:show` reads: the public summary's current step and the step list's running rows.
    $this->summary = function (): array {
        $summary = OperationData::summary($this->operation->refresh())->toArray();
        $running = array_keys(array_filter($this->operation->state['native_progress']['steps'] ?? [], static fn (array $row): bool => $row['status'] === 'running'));

        return [$summary['current_step'] ?? null, $running];
    };
    $this->step = fn (string $name): OperationEngineStep => OperationEngineStep::query()->where('operation_id', $this->operation->id)->where('name', $name)->firstOrFail();
});

it('waits 31 s after the DNS receipt on a scheduled deadline, then withdraws once', function (): void {
    ($this->dnsReceipt)();
    $done = ($this->step)('dns-0')->done_ms;

    expect($this->bus->sent)->toBe(['dns-0'])
        ->and($this->bus->deadlines)->toContain(['grace', $done + 31_000])
        ->and(($this->step)('grace')->status)->toBe('sent')
        ->and(($this->summary)())->toBe(['grace', ['grace']]);

    // A deadline before the grace ends, such as another step's, runs nothing; the grace stays the current step.
    $this->travel(30_999)->milliseconds();
    ($this->deadline)();
    expect($this->bus->sent)->toBe(['dns-0'])
        ->and(($this->summary)())->toBe(['grace', ['grace']]);

    $this->travel(1)->milliseconds();
    ($this->deadline)();
    ($this->deadline)();
    expect($this->bus->sent)->toBe(['dns-0', 'withdraw-3'])
        ->and(($this->step)('grace')->output)->toBe('waited 31000 ms after the DNS receipt')
        ->and(($this->step)('withdraw-3')->first_sent_ms - $done)->toBe(31_000)
        ->and(($this->summary)())->toBe(['withdraw-3', ['withdraw-3']]);
});

it('resumes what is left of the grace after a restart lost its deadline, and a redelivered DNS receipt does not move it', function (): void {
    ($this->dnsReceipt)();
    $done = ($this->step)('dns-0')->done_ms;
    $this->bus->deadlines = [];

    // The consumer restarts 20 s in: JetStream redelivers the unacknowledged DNS receipt, and the reconciler nudges.
    $this->travel(20)->seconds();
    ($this->dnsReceipt)();
    ($this->deadline)();
    expect(($this->step)('dns-0')->done_ms)->toBe($done)
        ->and(($this->step)('dns-0')->late)->toBe(1)
        ->and(($this->step)('grace')->resend_at_ms)->toBe($done + 31_000)
        ->and($this->bus->sent)->toBe(['dns-0'])
        ->and(($this->summary)())->toBe(['grace', ['grace']]);

    // The next nudge after the remaining 11 s runs the step; it does not wait 31 s again.
    $this->travel(11)->seconds();
    ($this->deadline)();
    expect($this->bus->sent)->toBe(['dns-0', 'withdraw-3'])
        ->and(($this->step)('withdraw-3')->first_sent_ms - $done)->toBe(31_000);
});

it('ends a cancel during the grace without withdrawing', function (): void {
    ($this->dnsReceipt)();
    $this->travel(10)->seconds();
    $this->operation->refresh()->forceFill(['cancel_requested_at' => now()])->save();
    app(Engine::class)->cancel($this->operation);
    ($this->deadline)();

    expect($this->operation->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($this->operation->lock_key)->toBeNull();

    $this->travel(30)->seconds();
    ($this->deadline)();
    expect($this->bus->sent)->toBe(['dns-0'])
        ->and(($this->step)('withdraw-3')->status)->toBe('pending');
});
