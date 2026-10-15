<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Processes\RestartProcessAction;
use App\Domain\Instances\Deployment\DeploymentErrorCode;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Process;
use Closure;
use Throwable;

final readonly class RestartInstanceProcessesAction
{
    public function __construct(
        private ProcessAdmissionLock $admissions,
        private ProcessRuntimeLease $lease,
        private RestartProcessAction $restart,
    ) {}

    /** @param (Closure(int): void)|null $onRestart */
    public function execute(Instance $instance, DeploymentRequest $request, ?Closure $onRestart = null): void
    {
        $this->admissions->run([$instance->id], function () use ($instance, $request, $onRestart): void {
            $processes = Process::query()
                ->whereIn('owner_type', Instance::morphTypes())
                ->where('owner_id', $instance->id)
                ->where('status', LifecycleStatus::Active)
                ->where('desired_state', DesiredProcessState::Running)
                ->where('restart_on_deploy', true)
                ->orderBy('id')
                ->get();

            foreach ($processes as $process) {
                try {
                    $this->lease->run($process, function (Process $fresh) use ($request, $onRestart): void {
                        if ($fresh->status !== LifecycleStatus::Active || $fresh->desired_state !== DesiredProcessState::Running || ! $fresh->restart_on_deploy) {
                            return;
                        }

                        $this->restart->execute($fresh);
                        $onRestart?->__invoke($fresh->id);
                        $request->emit(new DeploymentEvent('process_restart', DeploymentOutputStream::Stdout, "Restarted Process {$fresh->id}.\n"));
                    });
                } catch (Throwable $exception) {
                    $code = DeploymentErrorCode::fromException($exception);
                    $request->emit(new DeploymentEvent('process_restart', DeploymentOutputStream::Stderr, "Failed to restart Process {$process->id}: {$code}.\n", important: true));

                    throw $exception;
                }
            }
        });
    }
}
