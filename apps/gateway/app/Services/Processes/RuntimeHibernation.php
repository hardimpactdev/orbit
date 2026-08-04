<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Actions\Processes\RecordProcessEvent;
use App\Actions\Processes\StopProcesses;
use App\Enums\ProcessEventType;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:excessive-parameter-list
 * @mago-expect lint:kan-defect
 */
final readonly class RuntimeHibernation
{
    public const string ACTIVATED = 'activated';

    public const string FORBIDDEN = 'forbidden';

    public const string NOT_FOUND = 'not_found';

    public const string FAILED = 'failed';

    public function __construct(
        private StopProcesses $stopProcesses,
        private RecordProcessEvent $recordProcessEvent,
        private ProcessRuntimeTargets $runtimeTargets,
        private RuntimeWakeConcurrentRunner $wakeConcurrentRunner,
        private ProcessStreamSleeper $sleeper,
        private RemoteRuntimeHibernation $remote,
        private RuntimeHibernationScopes $scopes,
    ) {}

    public function activate(string $type, int $id, ?Node $caller): string
    {
        $scope = $this->scopes->resolve($type, $id);

        if (! $scope instanceof RuntimeHibernationScope) {
            return self::NOT_FOUND;
        }

        if (
            ! $caller instanceof Node
            || ! $caller->is($scope->node)
            || ! $this->scopes->isDevelopmentNode($scope->node)
        ) {
            return self::FORBIDDEN;
        }

        $lockSeconds = (int) config('orbit.runtime_hibernation.lock_seconds', default: 120);
        $lock = Cache::lock(
            $scope->lockKey(),
            $lockSeconds,
        );

        try {
            $lockWaitSeconds = (int) config('orbit.runtime_hibernation.lock_wait_seconds', default: 90);

            $result = $lock->block(
                $lockWaitSeconds,
                fn (): string => $this->activateLocked($scope),
            );

            return is_string($result) ? $result : self::FAILED;
        } catch (LockTimeoutException) {
            return self::FAILED;
        }
    }

    private function activateLocked(RuntimeHibernationScope $scope): string
    {
        $states = $this->remote->states($scope->node, [$scope->key()]);
        $state = $states === null || $states === [] ? null : $states[0];

        if (! is_array($state)) {
            return self::FAILED;
        }

        if ($state['awake']) {
            return self::ACTIVATED;
        }

        if ($scope->context->lifecycleProcesses(null)->isNotEmpty()) {
            if (! $this->startWakeProcesses($scope)) {
                $this->stopAfterFailedStart($scope);

                return self::FAILED;
            }

            if (! $this->waitUntilRunning($scope)) {
                $this->stopAfterFailedStart($scope);

                return self::FAILED;
            }
        }

        return $this->remote->markAwake($scope->node, $scope->key())->successful()
            ? self::ACTIVATED
            : self::FAILED;
    }

    private function startWakeProcesses(RuntimeHibernationScope $scope): bool
    {
        $targets = $this->runtimeTargets->for($scope->context, null);
        $context = $scope->context;
        $node = $scope->node;

        /** @var list<array{process: Process, driver: ProcessRuntimeDrivers\ProcessRuntimeDriver, runtime_unit: string}> $targets */
        foreach ($targets as $target) {
            $process = $target['process'];
            $workspace = $context->runtimeWorkspaceFor($process);

            $this->recordProcessEvent->handle(
                ProcessEventType::Starting,
                $context->eventApp(),
                $workspace,
                $process,
                $node,
                $target['runtime_unit'],
            );
        }

        $tasks = [];

        foreach ($targets as $index => $target) {
            $driver = $target['driver'];
            $runtimeUnit = $target['runtime_unit'];
            $tasks[$index] = static fn (): bool => $driver->start($node, $runtimeUnit);
        }

        $results = $this->wakeConcurrentRunner->run($tasks);
        $failed = false;

        foreach ($targets as $index => $target) {
            $ok = ($results[$index] ?? false) === true;
            $process = $target['process'];
            $workspace = $context->runtimeWorkspaceFor($process);

            $this->recordProcessEvent->handle(
                $ok ? ProcessEventType::Started : ProcessEventType::Failed,
                $context->eventApp(),
                $workspace,
                $process,
                $node,
                $target['runtime_unit'],
            );

            $failed = $failed || ! $ok;
        }

        return ! $failed;
    }

    private function waitUntilRunning(RuntimeHibernationScope $scope): bool
    {
        $targets = $this->runtimeTargets->for($scope->context, null);
        $timeoutSeconds = (int) config(
            'orbit.runtime_hibernation.activation_readiness_timeout_seconds',
            default: 60,
        );
        $pollMilliseconds = (int) config(
            'orbit.runtime_hibernation.activation_readiness_poll_milliseconds',
            default: 200,
        );
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        $pollMicroseconds = max(1, $pollMilliseconds) * 1000;

        do {
            if ($this->allRunning($scope->node, $targets)) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            $this->sleeper->sleep($pollMicroseconds);
        } while (microtime(true) < $deadline);

        return $this->allRunning($scope->node, $targets);
    }

    /**
     * @param  array<int, array{process: Process, driver: ProcessRuntimeDrivers\ProcessRuntimeDriver, runtime_unit: string}>  $targets
     */
    private function allRunning(Node $node, array $targets): bool
    {
        foreach ($targets as $target) {
            if (! $target['driver']->isRunning($node, $target['runtime_unit'])) {
                return false;
            }
        }

        return true;
    }

    private function stopAfterFailedStart(RuntimeHibernationScope $scope): void
    {
        try {
            $this->stopProcesses->handle($scope->context, null);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
