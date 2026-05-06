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
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, 'start');
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        $command = $this->startCommand($model);

        if ($command === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'start');
        }

        $model->expected_state = 'running';
        $model->save();
        $model->loadMissing('node');

        if ($model->node === null) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', 'start', 1, 'Target node is missing.');
        }

        $result = $this->remoteShell->run($model->node, $command, ['throw' => false]);

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed($tool, $model->node->name, 'start', $result->exitCode, trim($result->stderr));
        }

        return $this->payloads->toArray($model);
    }

    private function startCommand(NodeTool $tool): ?string
    {
        $metadata = $this->catalog->probeMetadata($tool->name);
        $commands = is_array($metadata) && is_array($metadata['repair_commands'] ?? null)
            ? $metadata['repair_commands']
            : [];
        $command = $commands['lifecycle_running'] ?? null;

        return is_string($command) && $command !== '' ? $command : null;
    }
}
