<?php

declare(strict_types=1);

use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\MachineBus;
use App\Domain\Engine\StepSpec;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RouteUpdateEngineFlow;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;

uses(LazilyRefreshDatabase::class);

/** A machine bus that claims each publication, as NATS would publish it, and keeps the signed payloads and uploads. */
final class FencedRouteBus implements MachineBus
{
    /** @var list<array<string, mixed>> */
    public array $orders = [];

    /** @var list<string> */
    public array $uploads = [];

    public bool $publish = true;

    public function publishOrder(int $node, SignedExecutorOrder $order, ?Closure $claim = null): void
    {
        if (! $this->publish || ($claim !== null && ! $claim())) {
            return;
        }
        $this->orders[] = json_decode((string) base64_decode(explode('.', $order->wire)[0], true), true, flags: JSON_THROW_ON_ERROR);
    }

    public function publishStart(int $operation): void {}

    public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void {}

    public function nudge(int $operation, string $id): void {}

    public function putContent(int $node, string $content): array
    {
        $this->uploads[] = $content;

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

/** Two site orders on two Nodes, then a Gateway step after both. */
final class FencedRouteDefinition implements EngineDefinition
{
    public static int $locals = 0;

    public static ?string $finished = null;

    public function __construct(private int $a, private int $b) {}

    public function steps(Operation $operation): array
    {
        return [new StepSpec('csr-0', $this->a, 'tls.ensure_csr', (string) $this->a), new StepSpec('site-b', $this->b, 'route.publish_site', (string) $this->b),
            new StepSpec('cutover', 0, 'route.cutover', 'gateway', ['csr-0', 'site-b'], resendMs: 60_000)];
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        return ['caddy' => ['blob' => ['content' => "site {$step}\n"]]];
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        self::$locals++;

        return 'cutover';
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        self::$finished = $run->failed()?->name ?? ($run->succeeded() ? 'succeeded' : 'cancelled');
        $operation->forceFill(['status' => self::$finished === 'succeeded' ? OperationStatus::Succeeded : OperationStatus::Failed])->save();
    }
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-27 04:00:00');
    $pair = sodium_crypto_sign_keypair();
    config(['orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey($pair))]);
    $this->bus = new FencedRouteBus;
    $this->app->instance(MachineBus::class, $this->bus);
    $node = static fn (string $name, string $ip): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit']));
    [$this->a, $this->b] = [$node('app-dev', '10.44.0.2'), $node('web', '10.44.0.3')];
    FencedRouteDefinition::$locals = 0;
    FencedRouteDefinition::$finished = null;
    $this->app->instance(RouteUpdateEngineFlow::class, new FencedRouteDefinition($this->a->id, $this->b->id));
    $this->operation = Operation::query()->forceCreate(['kind' => 'route.update', 'status' => OperationStatus::Running, 'target_type' => 'route',
        'target_id' => 9, 'node_id' => null, 'input' => [], 'lock_key' => 'route:9']);
    $this->operation->forceFill(['state' => ['swamp_run_id' => 'engine-'.$this->operation->id]])->save();
    $this->start = function (): void {
        app(Engine::class)->start($this->operation);
        app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $this->operation->id]);
    };
    $this->receipt = fn (Node $node, string $step, array $line) => app(Engine::class)->onReceipt([
        'node' => $node->id, 'line' => ['operation' => $this->operation->id.':'.$step, ...$line],
    ]);
    $this->step = fn (string $name): OperationEngineStep => OperationEngineStep::query()->where('operation_id', $this->operation->id)->where('name', $name)->firstOrFail();
});

afterEach(fn () => Carbon::setTestNow());

it('signs a fenced order with its content uploaded, so the order names only digests', function (): void {
    ($this->start)();

    expect($this->bus->uploads)->toBe(["site csr-0\n", "site site-b\n"])
        ->and(json_encode($this->bus->orders, JSON_THROW_ON_ERROR))->not->toContain('"content"')->toContain('"sha256"');
});

it('takes a fenced step\'s output and applied receipts, also when the journal repeats them for a second send', function (): void {
    ($this->start)();
    ($this->receipt)($this->a, 'csr-0', ['status' => 'refused', 'reason' => 'replayed_nonce',
        'first' => ['operation' => $this->operation->id.':csr-0', 'status' => 'output', 'stdout' => 'CSR', 'action' => 'tls.ensure_csr', 'target' => (string) $this->a->id]]);
    ($this->receipt)($this->b, 'site-b', ['status' => 'applied', 'changes' => 2, 'action' => 'route.publish_site', 'target' => (string) $this->b->id]);

    expect(($this->step)('csr-0')->status)->toBe('done')->and(($this->step)('csr-0')->output)->toBe('CSR')
        ->and(($this->step)('site-b')->output)->toBe('2')
        ->and(FencedRouteDefinition::$finished)->toBe('succeeded');
});

it('resends a fenced Route step whose order expired before it ran, as the executor journals no result for it', function (): void {
    ($this->start)();
    ($this->receipt)($this->a, 'csr-0', ['status' => 'failed', 'code' => 'executor.order_expired', 'action' => 'tls.ensure_csr', 'target' => (string) $this->a->id]);

    expect(($this->step)('csr-0')->status)->toBe('sent')->and(($this->step)('csr-0')->error_code)->toBeNull();
});

it('keeps the fence after one Node fails while another Node\'s published order has no receipt, and ends once it has one', function (): void {
    ($this->start)();
    ($this->receipt)($this->a, 'csr-0', ['status' => 'failed', 'code' => 'route.publish_failed', 'action' => 'tls.ensure_csr', 'target' => (string) $this->a->id]);

    expect(FencedRouteDefinition::$finished)->toBeNull()
        ->and($this->operation->refresh()->state['engine']['finished_ms'])->toBeNull();

    ($this->receipt)($this->b, 'site-b', ['status' => 'applied', 'changes' => 1, 'action' => 'route.publish_site', 'target' => (string) $this->b->id]);
    expect(FencedRouteDefinition::$finished)->toBe('csr-0');
});

it('ends a cancelled fenced run once its published orders settled, though its next order was never published', function (): void {
    $this->bus->publish = false;
    ($this->start)();
    // Only the first order went out; the cancel comes before the second is published.
    $this->bus->publish = true;
    OperationEngineStep::query()->where('operation_id', $this->operation->id)->where('name', 'site-b')->update(['first_order_expires_ms' => null]);
    OperationEngineStep::query()->where('operation_id', $this->operation->id)->where('name', 'csr-0')->update(['first_order_expires_ms' => Engine::nowMs() + 60_000]);
    $this->operation->forceFill(['cancel_requested_at' => now()])->save();
    ($this->receipt)($this->a, 'csr-0', ['status' => 'output', 'stdout' => 'CSR', 'action' => 'tls.ensure_csr', 'target' => (string) $this->a->id]);

    expect(FencedRouteDefinition::$finished)->toBe('cancelled')
        ->and(FencedRouteDefinition::$locals)->toBe(0);
});

it('runs a fenced Gateway step again when its worker was lost after it was marked sent', function (): void {
    ($this->start)();
    // The worker that marked the cutover sent died before it ran: the step stays `sent` with no result.
    OperationEngineStep::query()->where('operation_id', $this->operation->id)->whereIn('name', ['csr-0', 'site-b'])->update(['status' => 'done']);
    OperationEngineStep::query()->where('operation_id', $this->operation->id)->where('name', 'cutover')
        ->update(['status' => 'sent', 'sends' => 1, 'first_sent_ms' => Engine::nowMs(), 'resend_at_ms' => Engine::nowMs() + 60_000]);

    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $this->operation->id]);
    expect(FencedRouteDefinition::$locals)->toBe(0);

    Carbon::setTestNow(now()->addSeconds(61));
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $this->operation->id]);
    expect(FencedRouteDefinition::$locals)->toBe(1)
        ->and(($this->step)('cutover')->status)->toBe('done')
        ->and(FencedRouteDefinition::$finished)->toBe('succeeded');
});
