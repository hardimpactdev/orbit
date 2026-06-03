<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;
use App\Models\NodeTool;
use App\Services\Processes\ProcessRuntimeDriverRegistry;

final readonly class ToolLifecycleManager
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private ToolPayloadMapper $payloads,
        private RemoteShell $remoteShell,
        private ToolRelatedProcessResolver $relatedProcesses,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function start(string $tool, ?string $node = null, ?string $app = null): array|ToolRegistryFailure
    {
        return $this->applyProcessAction($tool, $node, $app, 'start');
    }

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function stop(string $tool, ?string $node = null, ?string $app = null): array|ToolRegistryFailure
    {
        return $this->applyProcessAction($tool, $node, $app, 'stop');
    }

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function restart(string $tool, ?string $node = null, ?string $app = null): array|ToolRegistryFailure
    {
        return $this->applyProcessAction($tool, $node, $app, 'restart');
    }

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function reload(string $tool, ?string $node = null, ?string $app = null): array|ToolRegistryFailure
    {
        return $this->runIntentPreservingAction(
            tool: $tool,
            node: $node,
            app: $app,
            action: 'reload',
            repairCommandKey: 'lifecycle_reloaded',
        );
    }

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    private function applyProcessAction(string $tool, ?string $node, ?string $app, string $action): array|ToolRegistryFailure
    {
        $target = $this->relatedProcesses->resolve($tool, $node, $app, $action);

        if ($target instanceof ToolRegistryFailure) {
            return $target;
        }

        $driver = $this->runtimeDrivers->forProcess($target->process);
        $runtimeUnit = $driver->runtimeUnitName($target->app, $target->process, $target->workspace);
        $successful = match ($action) {
            'start' => $driver->start($target->node, $runtimeUnit),
            'stop' => $driver->stop($target->node, $runtimeUnit),
            'restart' => $driver->restart($target->node, $runtimeUnit),
            default => false,
        };

        if (! $successful) {
            return ToolRegistryFailure::remoteActionFailed($tool, $target->node->name, $action, 1, 'The process runtime backend reported a lifecycle failure.');
        }

        return $this->payloads->toArray($target->tool);
    }

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    private function runIntentPreservingAction(
        string $tool,
        ?string $node,
        ?string $app,
        string $action,
        string $repairCommandKey,
    ): array|ToolRegistryFailure {
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, $action);
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        $command = $this->repairCommand($model, $repairCommandKey);

        if ($command === null) {
            return ToolRegistryFailure::unsupportedAction($tool, $action);
        }

        $model->loadMissing('node');

        if ($model->node === null) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', $action, 1, 'Target node is missing.');
        }

        $result = $this->remoteShell->run($model->node, $command, ['throw' => false]);

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed($tool, $model->node->name, $action, $result->exitCode, trim($result->stderr));
        }

        return $this->payloads->toArray($model);
    }

    private function repairCommand(NodeTool $tool, string $key): ?string
    {
        return $this->catalog->repairCommand($tool->name, $key);
    }
}
