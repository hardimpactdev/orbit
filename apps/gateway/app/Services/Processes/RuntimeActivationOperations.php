<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\OperationRun;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Orbit\Core\Enums\OperationStatus;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class RuntimeActivationOperations
{
    public function __construct(
        private OperationRunRecorder $operationRuns,
        private RuntimeActivationRunnerLauncher $launcher,
        private RuntimeActivationFence $fence,
    ) {}

    /**
     * @param  list<array{key: string, label: string, present: bool, reconstructable: bool}>  $dependencies
     * @mago-expect lint:no-boolean-flag-parameter
     */
    public function currentOrBegin(
        RuntimeHibernationScope $scope,
        array $dependencies,
        bool $retry,
        bool $cold,
    ): OperationRun {
        $resolvedRun = null;

        try {
            Cache::lock("runtime-activation-operation:{$scope->key()}", 15)
                ->block(10, function () use ($scope, $dependencies, $retry, $cold, &$resolvedRun): void {
                    $current = $this->latest($scope);

                    if ($current instanceof OperationRun && ! $current->status->isTerminal()) {
                        if (! $this->isStale($current)) {
                            $resolvedRun = $current;

                            return;
                        }

                        if (! $this->takeOverStale($scope, $current)) {
                            $resolvedRun = $current->refresh();

                            return;
                        }
                    }

                    if (
                        $current instanceof OperationRun
                        && $current->status === OperationStatus::Failed
                        && ! $retry
                        && ! $this->failedRetryBackoffElapsed($current)
                    ) {
                        $resolvedRun = $current;

                        return;
                    }

                    $resolvedRun = $this->begin($scope, $dependencies, $cold);
                });
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException('Runtime activation operation lock timed out.', previous: $exception);
        }

        if (! $resolvedRun instanceof OperationRun) {
            throw new RuntimeException('Runtime activation operation did not resolve.');
        }

        return $resolvedRun;
    }

    public function latest(RuntimeHibernationScope $scope): ?OperationRun
    {
        return OperationRun::query()
            ->where('operation_id', $this->operationId($scope))
            ->where('operation_type', 'runtime-activation')
            ->latest('created_at')
            ->first();
    }

    /**
     * @param  list<array{key: string, label: string, present: bool, reconstructable: bool}>  $dependencies
     * @mago-expect lint:no-boolean-flag-parameter
     */
    private function begin(RuntimeHibernationScope $scope, array $dependencies, bool $cold): OperationRun
    {
        $plannedDependencies = $cold
            ? array_values(array_map(
                static fn (array $dependency): array => [
                    'key' => $dependency['key'],
                    'label' => $dependency['label'],
                ],
                $dependencies,
            ))
            : [];
        $processes = $scope
            ->context
            ->lifecycleProcesses(null)
            ->map(static fn ($process): array => [
                'id' => $process->id,
                'name' => $process->name,
                'label' => "Starting {$process->name}",
            ])
            ->values()
            ->all();
        $run = $this->operationRuns->queued(
            operationId: $this->operationId($scope),
            lane: 'gateway',
            operationType: 'runtime-activation',
            targetNodeId: $scope->node->id,
            result: [
                'runtime_activation' => [
                    'scope' => [
                        'type' => $scope->type,
                        'id' => $scope->id,
                    ],
                    'cold' => $cold,
                    'dependencies' => $plannedDependencies,
                    'processes' => $processes,
                ],
            ],
        );
        $steps = [];

        foreach ($plannedDependencies as $dependency) {
            $steps[] = [
                'key' => "dependency:{$dependency['key']}",
                'label' => $dependency['label'],
            ];
        }

        foreach ($processes as $process) {
            $steps[] = [
                'key' => "process:{$process['id']}",
                'label' => $process['label'],
            ];
        }

        $this->operationRuns->appendTree($run->id, 'Preparing your application', $steps);
        app()->terminating(fn () => $this->launch($run));

        return $run;
    }

    private function launch(OperationRun $run): void
    {
        try {
            $this->launcher->launch($run);
        } catch (RuntimeException $exception) {
            $this->operationRuns->appendError(
                $run->id,
                'The activation runner could not start.',
                data: ['reason' => 'runtime_activation_runner_launch_failed'],
            );
            $this->operationRuns->failed($run->id, error: [
                'code' => 'runtime_activation_runner_launch_failed',
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * A terminal failed activation stays failed only for a bounded backoff;
     * afterwards an ordinary request begins a fresh wake run instead of
     * serving the stale failed page until someone finds ?orbit-wake-retry=1.
     */
    private function failedRetryBackoffElapsed(OperationRun $run): bool
    {
        $backoff = (int) config(
            'orbit.runtime_hibernation.failed_activation_retry_seconds',
            default: 60,
        );

        $failedAt = $run->finished_at ?? $run->updated_at;

        return $failedAt->lte(now()->subSeconds(max(1, $backoff)));
    }

    private function isStale(OperationRun $run): bool
    {
        $timeout = match ($run->status) {
            OperationStatus::Queued => (int) config(
                'orbit.runtime_hibernation.activation_queued_timeout_seconds',
                default: 30,
            ),
            OperationStatus::Running => (int) config(
                'orbit.runtime_hibernation.activation_running_timeout_seconds',
                default: 1200,
            ),
            default => 0,
        };

        return $run->updated_at->lte(now()->subSeconds(max(1, $timeout)));
    }

    private function takeOverStale(RuntimeHibernationScope $scope, OperationRun $run): bool
    {
        return $this->fence->attemptTakeover($scope, function () use ($run): bool {
            $run->refresh();

            if ($run->status->isTerminal() || ! $this->isStale($run)) {
                return false;
            }

            $this->operationRuns->appendError(
                $run->id,
                'The activation runner stopped reporting progress.',
                data: ['reason' => 'runtime_activation_runner_stale'],
            );
            $this->operationRuns->failed($run->id, error: [
                'code' => 'runtime_activation_runner_stale',
                'message' => 'The activation runner stopped reporting progress.',
            ]);

            return true;
        });
    }

    private function operationId(RuntimeHibernationScope $scope): string
    {
        return "runtime-activation:{$scope->key()}";
    }
}
