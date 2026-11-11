<?php

declare(strict_types=1);

namespace App\Actions\Hibernation;

use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\HibernationWakeFailureStore;
use App\Models\Instance;
use SensitiveParameter;
use Throwable;

use function Illuminate\Support\defer;

final readonly class ScheduleInstanceRuntimeWakeAction
{
    public function __construct(
        private ActivateInstanceRuntimeAction $activate,
        private HibernationWakeFailureStore $failures,
    ) {}

    public function afterResponse(#[SensitiveParameter] Instance $instance, bool $retry = false): void
    {
        $instanceId = $instance->id;

        defer(callback: function () use ($instance, $instanceId, $retry): void {
            try {
                $this->activate->execute($instance, $retry);
            } catch (Throwable $exception) {
                if ($exception instanceof HibernationException && in_array($exception->errorCode, ['process.operation_busy', 'process.runtime_lock_failed', 'hibernation.publication_pending'], true)) {
                    return;
                }

                if (! $exception instanceof HibernationException) {
                    report($exception);
                }
                $this->failures->remember($instanceId, $exception instanceof HibernationException
                    ? $exception->getMessage() : 'The runtime wake failed. Check the Gateway log before retrying.');
            }
        }, always: true);
    }
}
