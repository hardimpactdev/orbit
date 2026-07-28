<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Actions\Processes\StopProcesses;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final readonly class RuntimeIdleHibernation
{
    private const int STATE_BATCH_SIZE = 200;

    public function __construct(
        private StopProcesses $stopProcesses,
        private RemoteRuntimeHibernation $remote,
        private RuntimeHibernationScopes $scopes,
    ) {}

    public function hibernate(?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $idleSeconds = (int) config('orbit.runtime_hibernation.idle_seconds', default: 3600);
        $cutoff = $now->subSeconds($idleSeconds)->getTimestamp();

        foreach ($this->scopes->byNode() as $nodeScopes) {
            foreach (array_chunk($nodeScopes, self::STATE_BATCH_SIZE) as $batch) {
                try {
                    $this->hibernateBatch($batch, $cutoff);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
    }

    /**
     * @param  list<RuntimeHibernationScope>  $scopes
     */
    private function hibernateBatch(array $scopes, int $cutoff): void
    {
        $node = $scopes[0]->node;
        $states = $this->remote->states(
            $node,
            array_map(static fn (RuntimeHibernationScope $scope): string => $scope->key(), $scopes),
        );

        if ($states === null) {
            return;
        }

        $statesByKey = [];

        foreach ($states as $state) {
            $statesByKey[$state['key']] = $state;
        }

        foreach ($scopes as $scope) {
            $state = $statesByKey[$scope->key()] ?? null;

            if (! $this->shouldHibernate($state, $cutoff)) {
                continue;
            }

            $lockSeconds = (int) config('orbit.runtime_hibernation.lock_seconds', default: 120);
            Cache::lock($scope->lockKey(), $lockSeconds)
                ->get(fn (): bool => $this->hibernateLocked($scope));
        }
    }

    /**
     * @param  array{key: string, awake: bool, hibernated: bool, last_activity_at: int|null}|null  $state
     */
    private function shouldHibernate(?array $state, int $cutoff): bool
    {
        $runtimeState = RuntimeHibernationState::from($state);

        if (! $runtimeState instanceof RuntimeHibernationState) {
            return false;
        }

        return $runtimeState->shouldHibernate($cutoff);
    }

    private function hibernateLocked(RuntimeHibernationScope $scope): bool
    {
        if (! $this->remote->markAsleep($scope->node, $scope->key())->successful()) {
            return false;
        }

        if ($scope->context->lifecycleProcesses(null)->isEmpty()) {
            return true;
        }

        try {
            $result = $this->stopProcesses->handle($scope->context, null);
        } catch (Throwable $exception) {
            report($exception);
            $this->remote->markAwake($scope->node, $scope->key());

            return false;
        }

        if ($result['failed']) {
            $this->remote->markAwake($scope->node, $scope->key());

            return false;
        }

        return true;
    }
}
