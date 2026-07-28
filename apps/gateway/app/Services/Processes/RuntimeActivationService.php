<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\Node;
use Orbit\Core\Enums\OperationStatus;
use RuntimeException;

final readonly class RuntimeActivationService
{
    public function __construct(
        private RuntimeHibernation $hibernation,
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
        $missingDependencies = is_array($state)
            ? array_values(array_filter(
                $state['dependencies'],
                static fn (array $dependency): bool => ! $dependency['present'] && $dependency['reconstructable'],
            ))
            : [];

        if ($missingDependencies !== []) {
            try {
                $run = $this->operations->currentOrBegin($scope, $missingDependencies, $retry);
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

        if ($cold && ! $this->coldStorage->markSourceWarm($scope)) {
            return new RuntimeActivationOutcome(RuntimeActivationOutcome::FAILED, $scope);
        }

        $status = match ($this->hibernation->activate($type, $id, $caller)) {
            RuntimeHibernation::ACTIVATED => RuntimeActivationOutcome::ACTIVATED,
            RuntimeHibernation::FORBIDDEN => RuntimeActivationOutcome::FORBIDDEN,
            RuntimeHibernation::NOT_FOUND => RuntimeActivationOutcome::NOT_FOUND,
            default => RuntimeActivationOutcome::FAILED,
        };

        return new RuntimeActivationOutcome($status, $scope);
    }
}
