<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\Node;
use App\Models\OperationRun;
use Orbit\Core\Enums\OperationStatus;
use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class RuntimeActivationService
{
    public function __construct(
        private RemoteRuntimeHibernation $remoteHibernation,
        private RuntimeHibernationScopes $scopes,
        private RemoteRuntimeDependencies $dependencies,
        private RuntimeDependencyColdStorage $coldStorage,
        private RuntimeActivationOperations $operations,
    ) {}

    public function activate(
        string $type,
        int $id,
        ?Node $caller,
        ?bool $cold = null,
        bool $retry = false,
    ): RuntimeActivationOutcome {
        $scope = $this->scopes->resolve($type, $id);

        if (! $scope instanceof RuntimeHibernationScope) {
            return new RuntimeActivationOutcome(RuntimeActivationOutcome::NOT_FOUND);
        }

        if (
            ! $caller instanceof Node
            || ! $caller->is($scope->node)
            || ! $this->scopes->isDevelopmentNode($scope->node)
        ) {
            return new RuntimeActivationOutcome(RuntimeActivationOutcome::FORBIDDEN, $scope);
        }

        if ($cold === null) {
            $cold = $this->coldStorage->isCold($scope);

            if ($cold === null) {
                return new RuntimeActivationOutcome(RuntimeActivationOutcome::FAILED, $scope);
            }
        }

        $state = $cold ? $this->dependencies->inspect($scope) : null;

        if ($cold && ! is_array($state)) {
            return new RuntimeActivationOutcome(RuntimeActivationOutcome::FAILED, $scope);
        }

        $missingDependencies = is_array($state)
            ? array_values(array_filter(
                $state['dependencies'],
                static fn (array $dependency): bool => ! $dependency['present'] && $dependency['reconstructable'],
            ))
            : [];
        $currentRun = $this->operations->latest($scope);
        // Only an in-flight (non-terminal) run skips the soft awake probe.
        // A terminal Failed run must still probe: if the scope is already awake
        // (race or external recovery), return 204 instead of a stale failed page.
        $inFlightRun = $currentRun instanceof OperationRun && ! $currentRun->status->isTerminal();

        if (
            ! $cold
            && $missingDependencies === []
            && ! $inFlightRun
        ) {
            $awake = $this->remoteHibernation->isAwake($scope->node, $scope->key());

            if ($awake === null) {
                return new RuntimeActivationOutcome(RuntimeActivationOutcome::FAILED, $scope);
            }

            if ($awake) {
                return new RuntimeActivationOutcome(RuntimeActivationOutcome::ACTIVATED, $scope);
            }
        }

        try {
            $run = $this->operations->currentOrBegin($scope, $missingDependencies, $retry, $cold);
        } catch (RuntimeException) {
            return new RuntimeActivationOutcome(RuntimeActivationOutcome::FAILED, $scope);
        }

        return new RuntimeActivationOutcome(
            $run->status === OperationStatus::Failed
                ? RuntimeActivationOutcome::FAILED
                : RuntimeActivationOutcome::WAKING,
            $scope,
            $run,
        );
    }
}
