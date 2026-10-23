<?php

declare(strict_types=1);

namespace App\Actions\Instances\Dependencies;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Instances\Dependencies\DependencyEcosystem;
use App\Domain\Instances\Dependencies\DependencyUpdateInspection;
use App\Domain\Instances\Dependencies\DependencyUpdateStepResult;
use App\Domain\Instances\Dependencies\InstanceDependencyUpdateResult;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;

final readonly class UpdateInstanceDependenciesAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $operations,
        private AppDevSourceOperationLock $sourceOperations,
        private UpdateEcosystemDependenciesAction $ecosystems,
        private ScanInstanceDependenciesAction $scan,
    ) {}

    public function execute(Instance $instance): InstanceDependencyUpdateResult
    {
        try {
            return $this->operations->run([$instance->id], function () use ($instance): InstanceDependencyUpdateResult {
                $current = Instance::query()->with('node')->find($instance->id);
                if ($current === null || ! $this->available($current)) {
                    return $this->refused($instance->id, 'dependencies.instance_unavailable');
                }
                if ($current->placedOnAppProd()) {
                    return $this->refused($current->id, 'dependencies.production_update_forbidden');
                }

                return $this->sourceOperations->synchronized(
                    $current->node_id,
                    fn (): InstanceDependencyUpdateResult => $this->update($current),
                );
            });
        } catch (ResourceOperationException) {
            return $this->refused($instance->id, 'dependencies.operation_busy');
        }
    }

    private function update(Instance $instance): InstanceDependencyUpdateResult
    {
        $yarn = $this->ecosystems->inspect($instance, 'yarn');
        if ($yarn->blocked()) {
            return $this->refused($instance->id, $yarn->errorCode ?? 'dependencies.unsupported_format');
        }

        $composerInspection = $this->ecosystems->inspect($instance, 'composer');
        if ($composerInspection->blocked()) {
            return $this->refused($instance->id, $composerInspection->errorCode ?? 'dependencies.unreadable_source');
        }

        [$javascriptEcosystem, $javascriptInspection] = $this->javascript($instance);
        if ($javascriptInspection->blocked()) {
            return $this->refused($instance->id, $javascriptInspection->errorCode ?? 'dependencies.unreadable_source');
        }

        $current = Instance::query()->with('node')->find($instance->id);
        if ($current === null || ! $this->available($current)) {
            return $this->refused($instance->id, 'dependencies.instance_unavailable');
        }

        $composer = DependencyUpdateStepResult::absent(DependencyEcosystem::Composer);
        if ($composerInspection->present) {
            $composer = $this->ecosystems->apply($current, 'composer');
            if (! $composer->completed()) {
                return $this->result($current, $composer, DependencyUpdateStepResult::notRun(DependencyEcosystem::Npm));
            }
        }

        $current = Instance::query()->with('node')->find($instance->id);
        if ($current === null || ! $this->available($current)) {
            return $this->result($instance, $composer, DependencyUpdateStepResult::notRun(DependencyEcosystem::Npm));
        }

        $javascript = DependencyUpdateStepResult::absent(DependencyEcosystem::Npm);
        if ($javascriptInspection->present) {
            if ($javascriptEcosystem === null || $javascriptInspection->toolPath === null) {
                $javascript = DependencyUpdateStepResult::failed(DependencyEcosystem::Npm, 'dependencies.unsupported_delegation', false);
            } else {
                $javascript = $this->ecosystems->apply($current, $javascriptEcosystem, $javascriptInspection->toolPath);
            }
        }

        return $this->result($current, $composer, $javascript);
    }

    /** @return array{0: 'npm'|'pnpm'|'bun'|null, 1: DependencyUpdateInspection} */
    private function javascript(Instance $instance): array
    {
        $selected = null;
        $inspection = DependencyUpdateInspection::absent();
        foreach (['npm', 'pnpm', 'bun'] as $ecosystem) {
            $candidate = $this->ecosystems->inspect($instance, $ecosystem);
            if ($candidate->blocked()) {
                return [$ecosystem, $candidate];
            }
            if (! $candidate->present) {
                continue;
            }
            if ($selected !== null) {
                return [$ecosystem, DependencyUpdateInspection::failed('dependencies.ambiguous_manager')];
            }
            $selected = $ecosystem;
            $inspection = $candidate;
        }

        return [$selected, $inspection];
    }

    private function result(Instance $instance, DependencyUpdateStepResult $composer, DependencyUpdateStepResult $javascript): InstanceDependencyUpdateResult
    {
        return new InstanceDependencyUpdateResult($instance->id, $composer, $javascript, $this->scan->execute($instance, discover: false));
    }

    private function refused(int $instanceId, string $errorCode): InstanceDependencyUpdateResult
    {
        return new InstanceDependencyUpdateResult(
            $instanceId,
            DependencyUpdateStepResult::notRun(DependencyEcosystem::Composer),
            DependencyUpdateStepResult::notRun(DependencyEcosystem::Npm),
            null,
            $errorCode,
        );
    }

    private function available(?Instance $instance): bool
    {
        return $instance !== null && $instance->status === InstanceState::Active
            && ! $instance->removalMember()->exists();
    }
}
