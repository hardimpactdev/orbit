<?php

declare(strict_types=1);

use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\MachineBus;
use App\Domain\Engine\StepSpec;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Instances\InstanceCreateEngineFlow;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskLifecycle;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

/** A machine bus that keeps the steps the engine sends. */
final class FailureStepBus implements MachineBus
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

    /** @var list<string> */
    public array $deadlines = [];

    public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void
    {
        $this->deadlines[] = "{$step}:{$sends}";
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

/** Two steps in a row, and a stop that runs only after a failure and only when the first step made something to stop. */
final class FailureStepDefinition implements EngineDefinition
{
    public static ?string $finished = null;

    /** How many more times the projection throws, and how many times it ran. */
    public static int $throws = 0;

    public static int $finishes = 0;

    /** How many more times the projection throws after it wrote the terminal result. */
    public static int $throwsAfterEnd = 0;

    /** The projection's network read, before it takes the writer (a Node, the vault, the bus). */
    public static ?Closure $network = null;

    public function __construct(private int $node) {}

    public function steps(Operation $operation): array
    {
        return [
            new StepSpec('make', $this->node, 'instance.clone', (string) $this->node),
            new StepSpec('use', $this->node, 'instance.start', '7', ['make']),
            new StepSpec('stop', $this->node, 'instance.stop', '7', ['make'], onFailure: true),
            new StepSpec('extra', $this->node, 'instance.spare_volume', (string) $this->node, ['make'], optional: true),
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
        self::$finishes++;
        if (self::$throws > 0) {
            self::$throws--;

            throw new RuntimeException('projection refused');
        }
        self::$finished = ($run->failed()?->name ?? 'none').':'.($run->succeeded() ? 'succeeded' : 'not succeeded');
        if (self::$network !== null) {
            (self::$network)();
        }
        // As a projection must: the terminal result and the write that depends on it in one transaction.
        DB::transaction(static function () use ($operation, $run): void {
            $operation->forceFill(['status' => $run->failed() === null ? OperationStatus::Succeeded : OperationStatus::Failed])->save();
            if (self::$throwsAfterEnd > 0) {
                self::$throwsAfterEnd--;

                throw new RuntimeException('runtime write refused');
            }
            $operation->forceFill(['result' => ['runtime' => 'written']])->save();
        });
    }
}

beforeEach(function (): void {
    $pair = sodium_crypto_sign_keypair();
    config(['orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey($pair))]);
    $this->bus = new FailureStepBus;
    $this->app->instance(MachineBus::class, $this->bus);
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'app-dev', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
    ]));
    FailureStepDefinition::$finished = null;
    FailureStepDefinition::$throws = 0;
    FailureStepDefinition::$finishes = 0;
    FailureStepDefinition::$throwsAfterEnd = 0;
    FailureStepDefinition::$network = null;
    $this->app->instance(InstanceCreateEngineFlow::class, new FailureStepDefinition($this->node->id));
    $this->operation = Operation::query()->forceCreate(['kind' => 'instance.create', 'status' => OperationStatus::Running, 'target_type' => 'instance',
        'target_id' => 7, 'node_id' => $this->node->id, 'input' => []]);
    $this->operation->forceFill(['state' => ['swamp_run_id' => 'engine-'.$this->operation->id]])->save();
    app(Engine::class)->start($this->operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $this->operation->id]);
    $this->receipt = fn (string $step, array $line) => app(Engine::class)->onReceipt([
        'node' => $this->node->id, 'line' => ['operation' => $this->operation->id.':'.$step, ...$line],
    ]);
});

it('never sends a failure step of a run that succeeds, and ends without waiting for an optional step', function (): void {
    ($this->receipt)('make', ['status' => 'applied', 'changes' => 1]);
    ($this->receipt)('use', ['status' => 'applied', 'changes' => 1]);

    expect($this->bus->sent)->toBe(['make', 'use', 'extra'])
        ->and(FailureStepDefinition::$finished)->toBe('none:succeeded');
});

it('neither fails nor holds a run by its optional step', function (): void {
    ($this->receipt)('make', ['status' => 'applied', 'changes' => 1]);
    ($this->receipt)('extra', ['status' => 'failed', 'code' => 'instance.spare_volume_failed']);

    expect(FailureStepDefinition::$finished)->toBeNull();

    ($this->receipt)('use', ['status' => 'applied', 'changes' => 1]);

    expect(FailureStepDefinition::$finished)->toBe('none:succeeded')
        ->and($this->bus->sent)->not->toContain('stop');
});

it('sends a failure step after a failure, and ends the run only when it ended, naming the step that failed', function (): void {
    ($this->receipt)('make', ['status' => 'applied', 'changes' => 1]);
    ($this->receipt)('use', ['status' => 'failed', 'code' => 'instance.container_start_failed']);

    expect($this->bus->sent)->toBe(['make', 'use', 'extra', 'stop'])
        ->and(FailureStepDefinition::$finished)->toBeNull();

    ($this->receipt)('stop', ['status' => 'failed', 'code' => 'instance.stop_failed']);

    expect(FailureStepDefinition::$finished)->toBe('use:not succeeded');
});

it('skips a failure step whose own steps never ran, and ends at once', function (): void {
    ($this->receipt)('make', ['status' => 'failed', 'code' => 'instance.clone_failed']);

    expect($this->bus->sent)->toBe(['make'])
        ->and(OperationEngineStep::query()->where('operation_id', $this->operation->id)->where('name', 'stop')->value('status'))->toBe('skipped')
        ->and(FailureStepDefinition::$finished)->toBe('make:not succeeded');
});

it('fails the Operation once after the bounded projection retries, and redelivers nothing after', function (): void {
    FailureStepDefinition::$throws = PHP_INT_MAX;
    ($this->receipt)('make', ['status' => 'applied', 'changes' => 1]);
    ($this->receipt)('use', ['status' => 'applied', 'changes' => 1]);
    // A redelivered receipt inside the backoff neither runs the projection nor throws to the consumer.
    ($this->receipt)('use', ['status' => 'applied', 'changes' => 1]);
    expect(FailureStepDefinition::$finishes)->toBe(1);
    foreach ([1, 2] as $retry) {
        $this->travel(10)->seconds();
        app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $this->operation->id]);
    }
    $operation = $this->operation->refresh();

    expect(FailureStepDefinition::$finishes)->toBe(3)
        ->and(array_values(array_filter($this->bus->deadlines, static fn (string $d): bool => str_starts_with($d, 'projection:'))))->toBe(['projection:1', 'projection:2'])
        ->and($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe(Engine::ProjectionFailed)
        ->and($operation->failed_step)->toBe(Engine::ProjectionStep)
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->state['engine']['finish_attempts'])->toBe(3);

    $this->travel(60)->seconds();
    ($this->receipt)('use', ['status' => 'applied', 'changes' => 1]);
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $this->operation->id]);

    expect(FailureStepDefinition::$finishes)->toBe(3)
        ->and($this->operation->refresh()->status)->toBe(OperationStatus::Failed);
});

it('recovers from a projection that fails once within the bound', function (): void {
    FailureStepDefinition::$throws = 1;
    ($this->receipt)('make', ['status' => 'applied', 'changes' => 1]);
    ($this->receipt)('use', ['status' => 'applied', 'changes' => 1]);
    $this->travel(3)->seconds();
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $this->operation->id]);

    expect(FailureStepDefinition::$finishes)->toBe(2)
        ->and(FailureStepDefinition::$finished)->toBe('none:succeeded')
        ->and($this->operation->refresh()->status)->toBe(OperationStatus::Succeeded);
});

it('ends the Operation and frees its lock when the terminal write\'s own lifecycle hook throws, and reruns nothing after', function (): void {
    // The create's lifecycle hook runs on every terminal write: here it is what keeps failing.
    $this->app->instance(TaskLifecycle::class, new class
    {
        public int $calls = 0;

        public function createEnded(Operation $operation): void
        {
            $this->calls++;

            throw new RuntimeException('linked task update refused');
        }
    });
    $this->operation->forceFill(['lock_key' => 'instance:7'])->save();
    FailureStepDefinition::$throws = PHP_INT_MAX;
    ($this->receipt)('make', ['status' => 'applied', 'changes' => 1]);
    ($this->receipt)('use', ['status' => 'applied', 'changes' => 1]);
    foreach ([1, 2] as $retry) {
        $this->travel(10)->seconds();
        app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $this->operation->id]);
    }
    $operation = $this->operation->refresh();

    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe(Engine::ProjectionFailed)
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->state['engine']['finish_attempts'])->toBe(3)
        ->and(app(TaskLifecycle::class)->calls)->toBe(1);

    $this->travel(60)->seconds();
    ($this->receipt)('use', ['status' => 'applied', 'changes' => 1]);
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $this->operation->id]);

    expect(FailureStepDefinition::$finishes)->toBe(3)
        ->and(app(TaskLifecycle::class)->calls)->toBe(1);
});

it('rolls back a terminal result whose following write failed, and retries until both commit', function (): void {
    FailureStepDefinition::$throwsAfterEnd = 1;
    ($this->receipt)('make', ['status' => 'applied', 'changes' => 1]);
    ($this->receipt)('use', ['status' => 'applied', 'changes' => 1]);

    expect($this->operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($this->operation->result)->toBeNull();

    $this->travel(3)->seconds();
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $this->operation->id]);

    expect(FailureStepDefinition::$finishes)->toBe(2)
        ->and($this->operation->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($this->operation->result)->toBe(['runtime' => 'written']);
});

it('runs a projection\'s network read outside any transaction, so it holds no writer lock while it waits', function (): void {
    $outside = DB::transactionLevel();
    $levels = [];
    FailureStepDefinition::$network = static function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    };
    ($this->receipt)('make', ['status' => 'applied', 'changes' => 1]);
    ($this->receipt)('use', ['status' => 'applied', 'changes' => 1]);

    expect($levels)->toBe([$outside])
        ->and($this->operation->refresh()->status)->toBe(OperationStatus::Succeeded);
});
