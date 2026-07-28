<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Actions\Processes\StartProcesses;
use App\Actions\Processes\StopProcesses;
use App\Models\Node;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

final readonly class RuntimeHibernation
{
    public const string ACTIVATED = 'activated';

    public const string FORBIDDEN = 'forbidden';

    public const string NOT_FOUND = 'not_found';

    public const string FAILED = 'failed';

    public function __construct(
        private StartProcesses $startProcesses,
        private StopProcesses $stopProcesses,
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
            $result = $this->startProcesses->handle($scope->context, null);

            if ($result['failed']) {
                $this->stopAfterFailedStart($scope);

                return self::FAILED;
            }
        }

        return $this->remote->markAwake($scope->node, $scope->key())->successful()
            ? self::ACTIVATED
            : self::FAILED;
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
