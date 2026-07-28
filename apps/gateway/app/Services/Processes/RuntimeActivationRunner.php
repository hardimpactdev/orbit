<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\OperationRun;
use App\Services\Operations\OperationRunRecorder;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class RuntimeActivationRunner
{
    public function __construct(
        private RuntimeHibernationScopes $scopes,
        private RemoteRuntimeDependencies $dependencies,
        private RuntimeDependencyColdStorage $coldStorage,
        private RuntimeHibernation $hibernation,
        private OperationRunRecorder $operationRuns,
    ) {}

    public function run(string $operationRunId): void
    {
        $run = OperationRun::query()->find($operationRunId);

        if (! $run instanceof OperationRun || $run->operation_type !== 'runtime-activation') {
            throw new RuntimeException('Runtime activation operation was not found.');
        }

        if ($run->status->isTerminal()) {
            return;
        }

        $plan = $this->plan($run);

        if ($plan === null) {
            $this->fail($run, 'runtime_activation_plan_invalid', 'The runtime activation plan is invalid.');

            return;
        }

        $scope = $this->scopes->resolve(
            $plan['scope']['type'],
            $plan['scope']['id'],
        );

        if (! $scope instanceof RuntimeHibernationScope) {
            $this->fail($run, 'runtime_scope_not_found', 'The runtime scope is no longer available.');

            return;
        }

        $this->operationRuns->running($run->id);

        try {
            $this->restoreDependencies($run, $scope, $plan['dependencies']);
            $this->startProcesses($run, $scope, $plan['processes']);
            $this->heartbeat($run);

            if (! $this->coldStorage->markSourceWarm($scope)) {
                throw new RuntimeException('Runtime cold state could not be cleared.');
            }

            $this->heartbeat($run);
            $this->operationRuns->appendComplete($run->id, 0);
            $this->operationRuns->succeeded($run->id, result: [
                'runtime_activation' => [
                    'scope' => $scope->key(),
                ],
            ]);
        } catch (Throwable $exception) {
            if ($run->refresh()->status->isTerminal()) {
                return;
            }

            report($exception);
            $this->fail(
                $run,
                'runtime_activation_failed',
                'The application could not be prepared.',
            );
        }
    }

    /**
     * @param  list<array{key: string, label: string}>  $dependencies
     */
    private function restoreDependencies(
        OperationRun $run,
        RuntimeHibernationScope $scope,
        array $dependencies,
    ): void {
        foreach ($dependencies as $dependency) {
            $key = $dependency['key'];
            $stepKey = "dependency:{$key}";
            $this->operationRuns->appendStep($run->id, $stepKey, 'active');
            $this->heartbeat($run);

            if (! $this->dependencies->restore($scope, $key)) {
                $this->operationRuns->appendStep($run->id, $stepKey, 'failed');

                throw new RuntimeException("Runtime dependency [{$key}] could not be restored.");
            }

            $this->heartbeat($run);
            $this->operationRuns->appendStep($run->id, $stepKey, 'done');
        }
    }

    /**
     * @param  list<array{id: int, name: string, label: string}>  $processes
     */
    private function startProcesses(
        OperationRun $run,
        RuntimeHibernationScope $scope,
        array $processes,
    ): void {
        foreach ($processes as $process) {
            $this->operationRuns->appendStep($run->id, "process:{$process['id']}", 'active');
        }

        $this->heartbeat($run);

        if (
            $this->hibernation->activate($scope->type, $scope->id, $scope->node) !== RuntimeHibernation::ACTIVATED
        ) {
            foreach ($processes as $process) {
                $this->operationRuns->appendStep($run->id, "process:{$process['id']}", 'failed');
            }

            throw new RuntimeException('Runtime processes could not be started.');
        }

        $this->heartbeat($run);

        foreach ($processes as $process) {
            $this->operationRuns->appendStep($run->id, "process:{$process['id']}", 'done');
        }
    }

    /**
     * @return array{
     *     scope: array{type: string, id: int},
     *     dependencies: list<array{key: string, label: string}>,
     *     processes: list<array{id: int, name: string, label: string}>
     * }|null
     */
    private function plan(OperationRun $run): ?array
    {
        $result = $run->result;

        if (! is_array($result) || ! is_array($result['runtime_activation'] ?? null)) {
            return null;
        }

        $rawPlan = $result['runtime_activation'];
        $scope = $this->scopePlan($rawPlan['scope'] ?? null);
        $dependencies = $this->dependencyPlan($rawPlan['dependencies'] ?? null);
        $processes = $this->processPlan($rawPlan['processes'] ?? null);

        if ($scope === null || $dependencies === null || $processes === null) {
            return null;
        }

        return [
            'scope' => $scope,
            'dependencies' => $dependencies,
            'processes' => $processes,
        ];
    }

    /**
     * @return array{type: string, id: int}|null
     */
    private function scopePlan(mixed $scope): ?array
    {
        if (
            ! is_array($scope)
            || ! is_string($scope['type'] ?? null)
            || ! is_int($scope['id'] ?? null)
        ) {
            return null;
        }

        return [
            'type' => $scope['type'],
            'id' => $scope['id'],
        ];
    }

    /**
     * @return list<array{key: string, label: string}>|null
     */
    private function dependencyPlan(mixed $dependencies): ?array
    {
        if (! is_array($dependencies)) {
            return null;
        }

        $plan = [];

        /** @mago-expect analyzer:mixed-assignment */
        foreach ($dependencies as $dependency) {
            if (
                ! is_array($dependency)
                || ! is_string($dependency['key'] ?? null)
                || ! is_string($dependency['label'] ?? null)
            ) {
                return null;
            }

            $plan[] = [
                'key' => $dependency['key'],
                'label' => $dependency['label'],
            ];
        }

        return $plan;
    }

    /**
     * @return list<array{id: int, name: string, label: string}>|null
     */
    private function processPlan(mixed $processes): ?array
    {
        if (! is_array($processes)) {
            return null;
        }

        $plan = [];

        /** @mago-expect analyzer:mixed-assignment */
        foreach ($processes as $process) {
            if (
                ! is_array($process)
                || ! is_int($process['id'] ?? null)
                || ! is_string($process['name'] ?? null)
                || ! is_string($process['label'] ?? null)
            ) {
                return null;
            }

            $plan[] = [
                'id' => $process['id'],
                'name' => $process['name'],
                'label' => $process['label'],
            ];
        }

        return $plan;
    }

    private function fail(OperationRun $run, string $code, string $message): void
    {
        $this->operationRuns->appendError($run->id, $message, data: ['reason' => $code]);
        $this->operationRuns->failed($run->id, error: [
            'code' => $code,
            'message' => $message,
        ]);
    }

    private function heartbeat(OperationRun $run): void
    {
        if ($this->operationRuns->heartbeat($run->id)->status->isTerminal()) {
            throw new RuntimeException('Runtime activation was superseded.');
        }
    }
}
