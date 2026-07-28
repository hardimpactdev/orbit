<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Actions\Processes\RestartProcesses;
use App\Actions\Processes\StartProcesses;
use App\Actions\Processes\StopProcesses;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class ProcessLifecycle
{
    public function __construct(
        private StartProcesses $startProcesses,
        private StopProcesses $stopProcesses,
        private RestartProcesses $restartProcesses,
        private ProcessRuntimeTargets $runtimeTargets,
        private RemoteRuntimeHibernation $remote,
        private RuntimeHibernationScopes $scopes,
    ) {}

    /**
     * @return array{data: array<string, mixed>, failed: bool, meta: array<string, mixed>, message: string}
     */
    public function start(ProcessOwnerContext $context, ?string $name): array
    {
        $scope = $this->scope($context, $name);

        if (! $scope instanceof RuntimeHibernationScope) {
            return $this->startProcesses->handle($context, $name);
        }

        $this->runtimeTargets->for($context, $name);

        return $this->withScopeLock(
            $scope,
            fn (): array => $this->startLocked($scope),
            'started',
        );
    }

    /**
     * @return array{data: array<string, mixed>, failed: bool, meta: array<string, mixed>, message: string}
     */
    public function stop(ProcessOwnerContext $context, ?string $name): array
    {
        $scope = $this->scope($context, $name);

        if (! $scope instanceof RuntimeHibernationScope) {
            return $this->stopProcesses->handle($context, $name);
        }

        $this->runtimeTargets->for($context, $name);

        return $this->withScopeLock(
            $scope,
            fn (): array => $this->stopLocked($scope),
            'stopped',
        );
    }

    /**
     * @return array{data: array<string, mixed>, failed: bool, meta: array<string, mixed>, message: string}
     */
    public function restart(ProcessOwnerContext $context, ?string $name): array
    {
        $scope = $this->scope($context, $name);

        if (! $scope instanceof RuntimeHibernationScope) {
            return $this->restartProcesses->handle($context, $name);
        }

        $this->runtimeTargets->for($context, $name);

        return $this->withScopeLock(
            $scope,
            fn (): array => $this->restartLocked($scope),
            'restarted',
        );
    }

    private function scope(ProcessOwnerContext $context, ?string $name): ?RuntimeHibernationScope
    {
        if ($name !== null) {
            return null;
        }

        $scope = $this->scopes->forContext($context);

        if (
            ! $scope instanceof RuntimeHibernationScope
            || ! $this->scopes->isDevelopmentNode($scope->node)
        ) {
            return null;
        }

        return $scope;
    }

    /**
     * @param  Closure(): array{
     *     data: array<string, mixed>,
     *     failed: bool,
     *     meta: array<string, mixed>,
     *     message: string
     * }  $callback
     * @return array{data: array<string, mixed>, failed: bool, meta: array<string, mixed>, message: string}
     */
    private function withScopeLock(
        RuntimeHibernationScope $scope,
        Closure $callback,
        string $pastTense,
    ): array {
        $lockSeconds = (int) config('orbit.runtime_hibernation.lock_seconds', default: 120);
        $lockWaitSeconds = (int) config('orbit.runtime_hibernation.lock_wait_seconds', default: 90);

        try {
            /** @var array{data: array<string, mixed>, failed: bool, meta: array<string, mixed>, message: string}|null $result */
            $result = Cache::lock($scope->lockKey(), $lockSeconds)
                ->block($lockWaitSeconds, $callback);

            return $result ?? $this->failure($pastTense, "none_{$pastTense}", 'runtime_lock_failed');
        } catch (LockTimeoutException) {
            return $this->failure($pastTense, "none_{$pastTense}", 'runtime_lock_timeout');
        }
    }

    /**
     * @return array{data: array<string, mixed>, failed: bool, meta: array<string, mixed>, message: string}
     */
    private function startLocked(RuntimeHibernationScope $scope): array
    {
        if (! $this->remote->markAsleep($scope->node, $scope->key())->successful()) {
            return $this->failure('started', 'none_started', 'runtime_asleep_marker_failed');
        }

        $result = $this->startProcesses->handle($scope->context, null);

        if ($result['failed']) {
            return $result;
        }

        if ($this->remote->markAwake($scope->node, $scope->key())->successful()) {
            return $result;
        }

        return [
            ...$result,
            'failed' => true,
            'message' => 'The development runtime started but could not be marked awake.',
            'meta' => [
                ...$result['meta'],
                'runtime_state' => 'awake_marker_failed',
            ],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, failed: bool, meta: array<string, mixed>, message: string}
     */
    private function stopLocked(RuntimeHibernationScope $scope): array
    {
        if (! $this->remote->markAsleep($scope->node, $scope->key())->successful()) {
            return $this->failure('stopped', 'none_stopped', 'runtime_asleep_marker_failed');
        }

        $result = $this->stopProcesses->handle($scope->context, null);

        if ($result['failed'] && ($result['meta']['partial_state'] ?? null) === 'none_stopped') {
            $this->remote->markAwake($scope->node, $scope->key());
        }

        return $result;
    }

    /**
     * @return array{data: array<string, mixed>, failed: bool, meta: array<string, mixed>, message: string}
     */
    private function restartLocked(RuntimeHibernationScope $scope): array
    {
        if (! $this->remote->markAsleep($scope->node, $scope->key())->successful()) {
            return $this->failure('restarted', 'none_restarted', 'runtime_asleep_marker_failed');
        }

        $result = $this->restartProcesses->handle($scope->context, null);

        if ($result['failed']) {
            if (($result['meta']['partial_state'] ?? null) === 'none_restarted') {
                $this->remote->markAwake($scope->node, $scope->key());
            }

            return $result;
        }

        if ($this->remote->markAwake($scope->node, $scope->key())->successful()) {
            return $result;
        }

        return [
            ...$result,
            'failed' => true,
            'message' => 'The development runtime restarted but could not be marked awake.',
            'meta' => [
                ...$result['meta'],
                'runtime_state' => 'awake_marker_failed',
            ],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, failed: bool, meta: array<string, mixed>, message: string}
     */
    private function failure(string $pastTense, string $partialState, string $runtimeState): array
    {
        return [
            'data' => ['runtimes' => []],
            'failed' => true,
            'message' => "The development runtime could not be {$pastTense}.",
            'meta' => [
                'process' => null,
                'partial_state' => $partialState,
                'runtime_state' => $runtimeState,
            ],
        ];
    }
}
