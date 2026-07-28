<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\ProcessEvent;
use Illuminate\Support\Carbon;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class RuntimeDependencyColdStorage
{
    public function __construct(
        private RemoteRuntimeDependencies $dependencies,
        private RemoteRuntimeHibernation $hibernation,
        private RuntimeHibernationScopes $scopes,
    ) {}

    /**
     * @param  array{key: string, awake: bool, hibernated: bool, cold: bool, last_activity_at: int|null}  $state
     */
    public function pruneIfEligible(
        RuntimeHibernationScope $scope,
        array $state,
        int $cutoff,
    ): void {
        if (
            $state['awake']
            || ! $state['hibernated']
            || $state['last_activity_at'] !== null
            && $state['last_activity_at'] > $cutoff
        ) {
            return;
        }

        $siblings = $this->sourceSiblings($scope);

        if ($this->hasRecentHttpActivity($scope, $siblings, $cutoff)) {
            return;
        }

        if ($this->hasRecentLifecycleActivity($siblings, $cutoff)) {
            return;
        }

        $dependencyState = $this->dependencies->inspect($scope);

        if (
            ! is_array($dependencyState)
            || $dependencyState['source_activity_at'] > $cutoff
            || ! $this->hasPrunableDependency($dependencyState['dependencies'])
        ) {
            return;
        }

        $markedCold = [];

        foreach ($siblings as $sibling) {
            if (! $this->hibernation->markCold($sibling->node, $sibling->key())->successful()) {
                $this->markScopesWarm($markedCold);

                return;
            }

            $markedCold[] = $sibling;
        }

        $this->dependencies->prune($scope);
    }

    public function markScopeWarm(RuntimeHibernationScope $scope): bool
    {
        return $this->hibernation->markWarm($scope->node, $scope->key())->successful();
    }

    public function isCold(RuntimeHibernationScope $scope): ?bool
    {
        $states = $this->hibernation->states($scope->node, [$scope->key()]);
        $state = $states === null || $states === [] ? null : $states[0];

        return is_array($state) ? $state['cold'] : null;
    }

    /**
     * @param  list<RuntimeHibernationScope>  $scopes
     */
    private function markScopesWarm(array $scopes): void
    {
        foreach ($scopes as $scope) {
            $this->hibernation->markWarm($scope->node, $scope->key());
        }
    }

    /**
     * @return list<RuntimeHibernationScope>
     */
    private function sourceSiblings(RuntimeHibernationScope $scope): array
    {
        $path = $scope->sourcePath();

        if ($path === null) {
            return [$scope];
        }

        return array_values(array_filter(
            $this->scopes->byNode()[$scope->node->id] ?? [$scope],
            static fn (RuntimeHibernationScope $candidate): bool => $candidate->sourcePath() === $path,
        ));
    }

    /**
     * @param  list<RuntimeHibernationScope>  $siblings
     */
    private function hasRecentHttpActivity(
        RuntimeHibernationScope $scope,
        array $siblings,
        int $cutoff,
    ): bool {
        if (count($siblings) === 1) {
            return false;
        }

        $states = $this->hibernation->states(
            $scope->node,
            array_map(static fn (RuntimeHibernationScope $sibling): string => $sibling->key(), $siblings),
        );

        if ($states === null) {
            return true;
        }

        return array_any(
            $states,
            static fn (array $state): bool => (
                $state['awake']
                || ! $state['hibernated']
                || $state['last_activity_at'] !== null
                && $state['last_activity_at'] > $cutoff
            ),
        );
    }

    /**
     * @param  list<RuntimeHibernationScope>  $siblings
     */
    private function hasRecentLifecycleActivity(array $siblings, int $cutoff): bool
    {
        foreach ($siblings as $scope) {
            $query = ProcessEvent::query()
                ->where('app_instance_id', $scope->context->appInstance?->id);

            if ($scope->context->workspace === null) {
                $query->whereNull('workspace_id');
            }

            if ($scope->context->workspace !== null) {
                $query->where('workspace_id', $scope->context->workspace->id);
            }

            $event = $query->latest('recorded_at')->first();

            if (
                $event instanceof ProcessEvent
                && $event->recorded_at instanceof Carbon
                && $event->recorded_at->getTimestamp() > $cutoff
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{key: string, label: string, present: bool, reconstructable: bool}>  $dependencies
     */
    private function hasPrunableDependency(array $dependencies): bool
    {
        return array_any(
            $dependencies,
            static fn (array $dependency): bool => $dependency['present'] && $dependency['reconstructable'],
        );
    }
}
