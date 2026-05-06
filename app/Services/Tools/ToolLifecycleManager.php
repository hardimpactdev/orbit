<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;
use App\Models\NodeTool;

final readonly class ToolLifecycleManager
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private ToolPayloadMapper $payloads,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function start(string $tool, ?string $node = null, ?string $app = null): array|ToolRegistryFailure
    {
        return $this->applyLifecycleAction(
            tool: $tool,
            node: $node,
            app: $app,
            action: 'start',
            expectedState: 'running',
            repairCommandKey: 'lifecycle_running',
        );
    }

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function stop(string $tool, ?string $node = null, ?string $app = null): array|ToolRegistryFailure
    {
        return $this->applyLifecycleAction(
            tool: $tool,
            node: $node,
            app: $app,
            action: 'stop',
            expectedState: 'installed',
            repairCommandKey: 'lifecycle_stopped',
        );
    }

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function restart(string $tool, ?string $node = null, ?string $app = null): array|ToolRegistryFailure
    {
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, 'restart');
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        $restartCommand = $this->repairCommand($model, 'lifecycle_restarted');
        $commands = $restartCommand === null ? [] : [$restartCommand];

        if ($restartCommand === null) {
            $commands = array_values(array_filter([
                $this->repairCommand($model, 'lifecycle_stopped'),
                $this->repairCommand($model, 'lifecycle_running'),
            ]));

            if (count($commands) !== 2) {
                return ToolRegistryFailure::unsupportedAction($tool, 'restart');
            }
        }

        $model->loadMissing('node');

        if ($model->node === null) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', 'restart', 1, 'Target node is missing.');
        }

        foreach ($commands as $command) {
            $result = $this->remoteShell->run($model->node, $command, ['throw' => false]);

            if (! $result->successful()) {
                return ToolRegistryFailure::remoteActionFailed($tool, $model->node->name, 'restart', $result->exitCode, trim($result->stderr));
            }
        }

        return $this->payloads->toArray($model);
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
    private function applyLifecycleAction(
        string $tool,
        ?string $node,
        ?string $app,
        string $action,
        string $expectedState,
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

        $model->expected_state = $expectedState;
        $model->save();
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
        $metadata = $this->catalog->probeMetadata($tool->name);
        $commands = is_array($metadata) && is_array($metadata['repair_commands'] ?? null)
            ? $metadata['repair_commands']
            : [];
        $command = $commands[$key] ?? null;

        return is_string($command) && $command !== '' ? $command : null;
    }
}
