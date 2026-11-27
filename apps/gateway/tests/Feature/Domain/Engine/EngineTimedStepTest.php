<?php

declare(strict_types=1);

use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineStepNotYet;
use App\Domain\Engine\MachineBus;
use App\Domain\Engine\StepSpec;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Instances\InstanceCreateEngineFlow;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;

uses(LazilyRefreshDatabase::class);

/** A machine bus that keeps the deadlines the engine schedules and the nudges it sends. */
final class TimedStepBus implements MachineBus
{
    /** @var list<array{string, int}> */
    public array $deadlines = [];

    public int $nudges = 0;

    public function publishOrder(int $node, SignedExecutorOrder $order, ?Closure $claim = null): void {}

    public function publishStart(int $operation): void {}

    public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void
    {
        $this->deadlines[] = [$step, $atMs];
    }

    public function nudge(int $operation, string $id): void
    {
        $this->nudges++;
    }

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

/** One Gateway step that may run only from a fixed time on, then one after it. */
final class TimedStepDefinition implements EngineDefinition
{
    public static int $runs = 0;

    public function __construct(private int $atMs) {}

    public function steps(Operation $operation): array
    {
        return [new StepSpec('grace', 0, 'route.dns_grace', 'gateway'), new StepSpec('after', 0, 'route.probe', 'gateway', ['grace'])];
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        return [];
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        self::$runs++;
        if ($step === 'grace' && Engine::nowMs() < $this->atMs) {
            throw new EngineStepNotYet($this->atMs);
        }

        return $step;
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $operation->forceFill(['status' => $run->succeeded() ? OperationStatus::Succeeded : OperationStatus::Failed])->save();
    }
}

it('parks a timed Gateway step without a worker and runs it when its deadline delivers at its time', function (): void {
    Carbon::setTestNow('2026-11-27 04:00:00');
    $at = Engine::nowMs() + 31_000;
    $bus = new TimedStepBus;
    $this->app->instance(MachineBus::class, $bus);
    TimedStepDefinition::$runs = 0;
    $this->app->instance(InstanceCreateEngineFlow::class, new TimedStepDefinition($at));
    $operation = Operation::query()->forceCreate(['kind' => 'instance.create', 'status' => OperationStatus::Running, 'target_type' => 'instance',
        'target_id' => 7, 'node_id' => null, 'input' => []]);
    $operation->forceFill(['state' => ['swamp_run_id' => 'engine-'.$operation->id]])->save();
    $step = fn (): OperationEngineStep => OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', 'grace')->firstOrFail();

    app(Engine::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    expect($step()->status)->toBe('waiting')->and($step()->resend_at_ms)->toBe($at)
        ->and($bus->deadlines)->toBe([['grace', $at]]);

    // An early event, such as another step's receipt, leaves it parked.
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);
    expect($step()->status)->toBe('waiting')->and(TimedStepDefinition::$runs)->toBe(1);

    // A lost schedule: the reconciler's nudge comes once the step is well past its time.
    Carbon::setTestNow(Carbon::createFromTimestampMs($at + 31_000));
    app(Engine::class)->nudgeIfStalled($operation);
    expect($bus->nudges)->toBe(1);

    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);
    expect($step()->status)->toBe('done')
        ->and($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    Carbon::setTestNow();
});
