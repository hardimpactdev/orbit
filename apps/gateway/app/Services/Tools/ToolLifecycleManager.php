<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;
use App\Models\Node;

final readonly class ToolLifecycleManager
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function run(
        string $tool,
        string $action,
        ?string $node = null,
        ?string $app = null,
    ): array|ToolRegistryFailure {
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, $action);
        }

        if ($this->catalog->lifecycleScript($tool, $action) === null) {
            return ToolRegistryFailure::unsupportedAction($tool, $action);
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        $model->loadMissing('node');

        if (! $model->node instanceof Node) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', $action, 1, 'Target node is missing.');
        }

        if (! $this->catalog->supportsNode($tool, $model->node)) {
            return ToolRegistryFailure::unsupportedOnNode(
                tool: $tool,
                node: $model->node->name,
                platform: $model->node->platform,
                supportedOperatingSystems: $this->catalog->supportedOperatingSystems($tool),
            );
        }

        $config = is_array($model->config) ? $model->config : [];
        $script = $this->catalog->lifecycleScript($tool, $action, $config);

        if ($script === null) {
            return ToolRegistryFailure::unsupportedAction($tool, $action);
        }

        $result = $this->remoteShell->run($model->node, $script, ['throw' => false]);

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed(
                tool: $tool,
                node: $model->node->name,
                action: $action,
                exitCode: $result->exitCode,
                stderr: trim($result->stderr),
            );
        }

        return [
            'name' => $tool,
            'node' => $model->node->name,
            'action' => $action,
        ];
    }
}
