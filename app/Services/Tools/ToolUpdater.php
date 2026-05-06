<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;

final readonly class ToolUpdater
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function update(
        string $tool,
        ?string $node = null,
        ?string $app = null,
        ?string $expectedVersion = null,
    ): array|ToolRegistryFailure {
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, 'update');
        }

        if (! $this->catalog->hasCapability($tool, 'update')) {
            return ToolRegistryFailure::unsupportedAction($tool, 'update');
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        $model->loadMissing('node');

        if ($model->node === null) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', 'update', 1, 'Target node is missing.');
        }

        $config = is_array($model->config) ? $model->config : [];
        $script = $this->catalog->updateScript($tool, $config);

        if ($script === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'update');
        }

        $result = $this->remoteShell->run($model->node, $script, ['throw' => false]);

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $model->node->name,
                'update',
                $result->exitCode,
                trim($result->stderr),
            );
        }

        if ($expectedVersion !== null) {
            $model->expected_version = $expectedVersion;
            $model->save();
        }

        return [
            'name' => $tool,
            'node' => $model->node->name,
            'version' => $model->expected_version,
        ];
    }
}
