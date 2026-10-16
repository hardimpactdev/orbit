<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentResult;
use App\Domain\Instances\Environment\InstanceEnvironmentRuntimeRefresher;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use Throwable;

final readonly class RefreshInstanceEnvironmentRuntimeAction
{
    public function __construct(
        private InstanceEnvironmentRuntimeRefresher $runtime,
        private RestartInstanceProcessesAction $processes,
    ) {}

    // Record immediately after explicit env-sync replaces .env, before later sync steps.
    public function recordPending(InstanceEnvironmentContext $context): void
    {
        if ($context->environment !== 'production') {
            return;
        }

        try {
            Instance::query()->findOrFail($context->instanceId)
                ->forceFill(['environment_runtime_pending_at' => now()])->saveOrFail();
        } catch (Throwable) {
            throw new ResourceOperationException(
                errorCode: 'env.runtime_refresh_failed',
                message: 'The environment file was synchronized, but Orbit could not record the pending runtime refresh. No refresh was attempted.',
                status: 502,
            );
        }
    }

    // Called only by explicit env-sync, while the Instance environment lock is held.
    public function execute(InstanceEnvironmentContext $context, InstanceEnvironmentResult $result): InstanceEnvironmentResult
    {
        if ($context->environment !== 'production') {
            return $result;
        }

        try {
            return $this->refresh($context, $result);
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ResourceOperationException(
                errorCode: 'env.runtime_refresh_failed',
                message: 'The environment file was synchronized, but runtime refresh could not be confirmed. Retry env-sync.',
                status: 502,
            );
        }
    }

    private function refresh(InstanceEnvironmentContext $context, InstanceEnvironmentResult $result): InstanceEnvironmentResult
    {
        $instance = Instance::query()->findOrFail($context->instanceId);
        $status = 'skipped_unchanged';
        $restarted = [];
        if ($result->environmentChanged || $instance->environment_runtime_pending_at !== null) {
            $this->recordPending($context);
            $status = $this->runtime->refresh($context);
            if (! str_starts_with($status, 'skipped_')) {
                try {
                    $this->processes->execute($instance, DeploymentRequest::withoutOutput(), function (int $id) use (&$restarted): void {
                        $restarted[] = $id;
                    });
                } catch (Throwable) {
                    throw new ResourceOperationException(
                        errorCode: 'env.process_restart_failed',
                        message: 'The production PHP runtime was refreshed, but an Instance Process restart failed. Check process:list and recover failed Processes with process:restart <process-id> before retrying env-sync; env-sync skips failed Processes.',
                        status: 502,
                        details: ['restarted_process_ids' => implode(',', $restarted)],
                    );
                }
                $instance->refresh()->forceFill(['environment_runtime_pending_at' => null])->saveOrFail();
            }
        }

        return new InstanceEnvironmentResult(
            instanceId: $result->instanceId,
            operation: $result->operation,
            changed: $result->changed,
            keyCount: $result->keyCount,
            testing: $result->testing,
            runtimeRefresh: $status,
            environmentChanged: $result->environmentChanged,
            restartedProcessIds: $restarted,
        );
    }
}
