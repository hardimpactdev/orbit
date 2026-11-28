<?php

declare(strict_types=1);

use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineSkip;
use App\Domain\Engine\MachineBus;
use App\Domain\Engine\StepSpec;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RouteEngineFlow;
use App\Domain\Routes\RouteHostSteps;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;

uses(LazilyRefreshDatabase::class);

/** A machine bus that counts the orders sent per step. */
final class RouteCreateReceiptBus implements MachineBus
{
    /** @var array<string, int> */
    public array $sent = [];

    public function publishOrder(int $node, SignedExecutorOrder $order, ?Closure $claim = null): void
    {
        if ($claim !== null && ! $claim()) {
            return;
        }
        $payload = json_decode((string) base64_decode(explode('.', $order->wire)[0], true), true, flags: JSON_THROW_ON_ERROR);
        $step = explode(':', $payload['operation'])[1];
        $this->sent[$step] = ($this->sent[$step] ?? 0) + 1;
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

/** route.create's two Node steps on one Node, with its actions: not a receipt-bound kind. */
final class RouteCreateReceiptDefinition implements EngineDefinition, EngineSkip
{
    public static ?string $finished = null;

    /** The site step is skipped when the request's recorded output says so, as an unlink step is when its read found no link. */
    public function skips(Operation $operation, string $step, EngineRun $run): bool
    {
        return str_starts_with($step, 'site-') && $run->output('csr-0') === 'SKIP-SITE';
    }

    public function __construct(private int $node) {}

    public function steps(Operation $operation): array
    {
        return [new StepSpec('csr-0', $this->node, 'tls.ensure_csr', (string) $this->node, resendMs: 15_000),
            new StepSpec('site-'.$this->node, $this->node, 'route.publish_site', (string) $this->node, ['csr-0'], resendMs: 30_000)];
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
        self::$finished = $run->failed()?->name ?? ($run->succeeded() ? 'succeeded' : 'cancelled');
        $operation->forceFill(['status' => self::$finished === 'succeeded' ? OperationStatus::Succeeded : OperationStatus::Failed])->save();
    }
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-27 04:00:00');
    $pair = sodium_crypto_sign_keypair();
    config(['orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey($pair))]);
    $this->bus = new RouteCreateReceiptBus;
    $this->app->instance(MachineBus::class, $this->bus);
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'app-dev', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit']));
    RouteCreateReceiptDefinition::$finished = null;
    $this->app->instance(RouteEngineFlow::class, new RouteCreateReceiptDefinition($this->node->id));
    $this->operation = Operation::query()->forceCreate(['kind' => 'route.create', 'status' => OperationStatus::Running, 'target_type' => 'route',
        'target_id' => 9, 'node_id' => null, 'input' => []]);
    $this->operation->forceFill(['state' => ['swamp_run_id' => 'engine-'.$this->operation->id]])->save();
    app(Engine::class)->start($this->operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $this->operation->id]);
    $this->receipt = fn (string $step, array $line) => app(Engine::class)->onReceipt([
        'node' => $this->node->id, 'line' => ['operation' => $this->operation->id.':'.$step, ...$line],
    ]);
    $this->site = 'site-'.$this->node->id;
});

afterEach(fn () => Carbon::setTestNow());

it('sends a route.create step again after its order expired before the lock, and the fresh order\'s result settles it', function (): void {
    expect(Engine::requiresReceipt($this->operation))->toBeFalse();
    ($this->receipt)('csr-0', ['status' => 'failed', 'code' => 'executor.order_expired']);
    // The journal keeps no result for an expiry before the lock, so the resent order runs and answers once.
    ($this->receipt)('csr-0', ['status' => 'output', 'stdout' => 'CSR']);
    ($this->receipt)($this->site, ['status' => 'applied', 'changes' => 1]);

    expect($this->bus->sent)->toBe(['csr-0' => 2, $this->site => 1])
        ->and(RouteCreateReceiptDefinition::$finished)->toBe('succeeded');
});

it('resends a route.create step whose receipt was lost and settles it once from the journal\'s repeat', function (): void {
    Carbon::setTestNow(now()->addSeconds(16));
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $this->operation->id]);
    ($this->receipt)('csr-0', ['status' => 'output', 'stdout' => 'CSR', 'repeat' => true]);
    ($this->receipt)('csr-0', ['status' => 'output', 'stdout' => 'CSR']);
    ($this->receipt)($this->site, ['status' => 'applied', 'changes' => 1]);

    $csr = OperationEngineStep::query()->where('operation_id', $this->operation->id)->where('name', 'csr-0')->firstOrFail();
    expect($this->bus->sent)->toBe(['csr-0' => 2, $this->site => 1])
        ->and($csr->output)->toBe('CSR')->and($csr->late)->toBe(1)
        ->and(RouteCreateReceiptDefinition::$finished)->toBe('succeeded');
});

it('records a file.read receipt in the executor\'s shape as the step output whose digest the next write sends', function (string $stdout, ?string $digest): void {
    // orbit-executor `file_read`: `Outcome::Output { stdout: serde_json::json!(read) }`, a `Read` tagged by `state`.
    ($this->receipt)('csr-0', ['status' => 'output', 'stdout' => $stdout]);

    $read = OperationEngineStep::query()->where('operation_id', $this->operation->id)->where('name', 'csr-0')->firstOrFail();
    expect($read->output)->toBe($stdout)
        ->and(RouteHostSteps::digest($read->output))->toBe($digest);
})->with([
    'a plain file' => ['{"state":"present","type":"file","sha256":"'.str_repeat('ab', 32).'","uid":0,"gid":0,"mode":"0644"}', str_repeat('ab', 32)],
    'a link, which has no digest' => ['{"state":"present","type":"symlink","uid":0,"gid":0,"mode":"0777"}', null],
    'nothing at the path' => ['{"state":"absent"}', null],
]);

it('marks a Node step the kind skips done without an order, and the run goes on', function (): void {
    ($this->receipt)('csr-0', ['status' => 'output', 'stdout' => 'SKIP-SITE']);

    $site = OperationEngineStep::query()->where('operation_id', $this->operation->id)->where('name', $this->site)->firstOrFail();
    expect($this->bus->sent)->toBe(['csr-0' => 1])
        ->and($site->status)->toBe('done')->and($site->sends)->toBe(0)
        ->and(RouteCreateReceiptDefinition::$finished)->toBe('succeeded');
});
