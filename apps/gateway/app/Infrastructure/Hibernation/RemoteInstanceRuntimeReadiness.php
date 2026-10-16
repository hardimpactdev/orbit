<?php

declare(strict_types=1);

namespace App\Infrastructure\Hibernation;

use App\Domain\AgentView\AgentProcessView;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\InstanceRuntimeReadiness;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Models\Instance;
use App\Models\Process;

final readonly class RemoteInstanceRuntimeReadiness implements InstanceRuntimeReadiness
{
    public function __construct(
        private ProcessRuntimeManager $runtime,
        private int $timeoutSeconds = RuntimeHibernation::DefaultWakeTimeoutSeconds,
        private ?AgentProcessView $agents = null,
    ) {}

    /** @param list<Process> $processes */
    public function waitUntilReady(Instance $instance, array $processes): void
    {
        $deadline = time() + $this->timeoutSeconds;

        foreach ($processes as $process) {
            $this->waitUntilObservedRunning($process, $deadline);
        }

        foreach ($processes as $process) {
            if ($process->isVpDev()) {
                $instance->refresh()->load('node');
                $runtime = app(VitePortRuntime::class);
                while (! $runtime->ready($process, $instance, $instance->vite_port ?? 0)) {
                    if (time() >= $deadline) {
                        throw new HibernationException('hibernation.development_server_not_ready', 'The owned Vite endpoint did not become ready before the wake timeout.');
                    }
                    usleep(250_000);
                }
            }
        }
    }

    private function waitUntilObservedRunning(Process $process, int $deadline): void
    {
        while (true) {
            $status = $this->observedStatus($process, $deadline);

            if (in_array($status, ['active', 'running'], true)) {
                return;
            }

            if ($status === 'failed') {
                throw new HibernationException(
                    errorCode: 'hibernation.runtime_failed',
                    message: "Process [{$process->name}] failed while waking.",
                );
            }

            if (time() >= $deadline) {
                throw new HibernationException(
                    errorCode: 'hibernation.runtime_not_ready',
                    message: "Process [{$process->name}] did not become ready before the wake timeout.",
                );
            }

            usleep(500_000);
        }
    }

    /**
     * The Process's state from a fresh agent view, without SSH. The Node answers instead when the
     * view cannot, when the view reports `failed`, which may predate this start, and at the
     * deadline, so a missed agent event never fails a wake on its own.
     */
    private function observedStatus(Process $process, int $deadline): string
    {
        $viewed = $this->agents?->status($process);

        if ($viewed === null || $viewed === 'failed' || time() >= $deadline) {
            return $this->runtime->status($process);
        }

        return $viewed;
    }
}
