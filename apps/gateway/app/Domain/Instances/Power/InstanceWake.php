<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\NestedFleetIntent;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;

/** `instance:wake` and the wake of a Route visit: one open wake per Instance, which a second caller joins. */
final readonly class InstanceWake extends InstancePower
{
    public const string Kind = 'instance.wake';

    protected function kind(): string
    {
        return self::Kind;
    }

    protected function powerState(): InstanceRuntimePowerState
    {
        return InstanceRuntimePowerState::Running;
    }

    protected function refuse(Instance $instance, InstanceRuntime $runtime): void {}

    /**
     * A nested-fleet clone never sleeps, so its wake is its start: the container, its Club key and its inner fleet
     * (InstanceStart, docs/reference/development-containers.md#a-stopped-nested-fleet-clone-starts). The request's Idempotency-Key
     * then keys that start. Every other Instance takes the wake below.
     */
    public function start(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        if (! NestedFleetIntent::cloneKeepsFleet($instance, $instance->runtime()->first())) {
            return parent::start($instance, $caller, $requestId);
        }
        $idempotency = app(OperationIdempotency::class);
        if ($idempotency->key === null || $idempotency->fingerprint === null) {
            return app(InstanceStart::class)->start($instance, $caller, $requestId);
        }

        return $idempotency->under(InstanceStart::Kind, $idempotency->key, $idempotency->fingerprint,
            fn (): Operation => app(InstanceStart::class)->start($instance, $caller, $requestId));
    }

    /**
     * FrankenPHP's port in the container, how long the wake waits for it to answer, and how long a 5xx answer may last before the
     * wake serves the app as it is: the marker goes once the app answers. A resumed container gets `resume_seconds` for its first
     * answer; a silent one is stopped and booted, which a visitor prefers to a wait without end.
     */
    protected function intent(): array
    {
        return ['web' => ['port' => InstanceContainer::WebPort, 'ready_seconds' => 30, 'settle_seconds' => 5, 'resume_seconds' => 10]];
    }

    /** The wake's path (`resumed`, `started`, `restarted_after_resume`, `already_running`), the marker it found and a resume's seconds. */
    protected function phaseKeys(): array
    {
        return ['wake', 'marker_found', 'resume_seconds', 'web_probe_seconds'];
    }

    /** A woken Instance's extensions catch up, such as Conn's renewal of a session that ended or ends soon while it slept. */
    protected function ended(Operation $operation): void
    {
        if ($operation->status === OperationStatus::Succeeded && ($instance = Instance::query()->find($operation->target_id)) !== null) {
            app(InstanceLifecycleContributions::class)->projectWake($instance);
        }
    }

    /**
     * A wake whose T3 stayed silent fails after the marker went: the container runs and its site serves the app, so the runtime
     * records `running` (docs/reference/development-containers.md#sleep-and-wake).
     */
    protected function reached(Operation $operation): bool
    {
        return parent::reached($operation) || $operation->error_code === 'instance.t3_unready';
    }

    protected function open(Instance $instance): ?Operation
    {
        return Operation::query()->where('kind', self::Kind)->where('target_type', 'instance')->where('target_id', $instance->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->orderBy('id')->first();
    }

    /**
     * The answer of the runtime activation endpoint for a container Instance behind its asleep marker: null while a wake is open
     * or was started now, else the message of the failed wake that the failure page shows until a retry.
     */
    public function activate(Instance $instance, bool $retry, string $caller): ?string
    {
        if (! $retry) {
            $last = Operation::query()->whereIn('kind', [self::Kind, InstanceSleep::Kind])->where('target_type', 'instance')
                ->where('target_id', $instance->id)->orderByDesc('id')->first();
            if ($last?->kind === self::Kind && $last->status === OperationStatus::Failed) {
                return 'The wake failed ('.($last->error_code ?? 'instance.wake_failed').'). Try again.';
            }
        }
        try {
            // A Route visit wakes a sleeping container only; a nested-fleet clone never sleeps, so it gets the wake's own refusal.
            parent::start($instance, $caller, null);
        } catch (ResourceOperationException $exception) {
            // Other lifecycle work holds the Instance: the page polls again, and a later request starts the wake.
            if (! in_array($exception->errorCode, ['operation.target_busy', 'instance.lifecycle_busy'], true)) {
                return $exception->getMessage();
            }
        }

        return null;
    }
}
