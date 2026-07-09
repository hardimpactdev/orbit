<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class ToolRemover
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private ToolScriptDispatcher $toolScriptDispatcher,
        private StaleToolIntentRemover $staleIntentRemover,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function remove(string $tool, ?string $node = null, ?string $app = null): array|ToolRegistryFailure
    {
        $model = $this->registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            $staleRouteCleanup = $this->staleIntentRemover->withoutRecord($tool, $node, $app);

            if ($staleRouteCleanup !== null) {
                return $staleRouteCleanup;
            }

            return $model;
        }

        $model->loadMissing('node');

        if ($model->node === null) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', 'remove', 1, 'Target node is missing.');
        }

        if (! $this->catalog->supports($tool) || ! $this->catalog->supportsNode($tool, $model->node)) {
            return $this->staleIntentRemover->withRecord($tool, $model);
        }

        if (! $this->catalog->hasCapability($tool, 'remove')) {
            return ToolRegistryFailure::unsupportedAction($tool, 'remove');
        }

        $script = $this->catalog->removeScript($tool, is_array($model->config) ? $model->config : []);

        if ($script === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'remove');
        }

        $result = $this->toolScriptDispatcher->runForRegistry(
            node: $model->node,
            tool: $tool,
            action: 'remove',
            script: $script,
        );

        if ($result instanceof ToolRegistryFailure) {
            return $result;
        }

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $model->node->name,
                'remove',
                $result->exitCode,
                trim($result->stderr),
            );
        }

        $model->credentials = null;
        $model->save();
        $model->delete();

        return [
            'name' => $tool,
            'node' => $model->node->name,
        ];
    }
}
