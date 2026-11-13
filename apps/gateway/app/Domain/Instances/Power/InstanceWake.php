<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
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
     * FrankenPHP's port in the container, how long the wake waits for it to answer, and how long a 5xx answer may last before the
     * wake serves the app as it is: the marker goes once the app answers.
     */
    protected function intent(): array
    {
        return ['web' => ['port' => InstanceContainer::WebPort, 'ready_seconds' => 30, 'settle_seconds' => 5]];
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
            $this->start($instance, $caller, null);
        } catch (ResourceOperationException $exception) {
            // Other lifecycle work holds the Instance: the page polls again, and a later request starts the wake.
            if (! in_array($exception->errorCode, ['operation.target_busy', 'instance.lifecycle_busy'], true)) {
                return $exception->getMessage();
            }
        }

        return null;
    }
}
