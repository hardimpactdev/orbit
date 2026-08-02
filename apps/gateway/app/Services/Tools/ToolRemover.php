<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Actions\Processes\RemoveProcess;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Processes\ProcessOwnerContextResolver;
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

final readonly class ToolRemover
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private ToolScriptDispatcher $toolScriptDispatcher,
        private StaleToolIntentRemover $staleIntentRemover,
        private ProcessOwnerContextResolver $processContexts,
        private RemoveProcess $removeProcess,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function remove(string $tool, ?string $node = null, ?string $app = null): array|ToolRegistryFailure
    {
        $stored = $this->registry->findStored(tool: $tool, node: $node, app: $app);

        if (
            $stored instanceof NodeTool
            && $stored->node instanceof Node
            && (! $this->catalog->supports($tool)
            || ! $this->catalog->supportsNode($tool, $stored->node))
        ) {
            return $this->staleIntentRemover->withRecord($tool, $stored);
        }

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

        // Stop and delete the related process unit before the tool remove script
        // so a restarting systemd unit cannot race the binary/home cleanup.
        $process = $this->removeRelatedProcess($model->node, $tool);

        if ($process instanceof ToolRegistryFailure) {
            return $process;
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

        $payload = [
            'name' => $tool,
            'node' => $model->node->name,
        ];

        if ($process !== null) {
            $payload['process'] = $process;
        }

        return $payload;
    }

    /**
     * @return array{name: string, runtime: string, tool: string, action: string}|ToolRegistryFailure|null
     */
    private function removeRelatedProcess(Node $node, string $tool): array|ToolRegistryFailure|null
    {
        $spec = $this->catalog->relatedProcess($tool);

        if ($spec === null) {
            return null;
        }

        try {
            $context = $this->processContexts->resolve(
                nodeName: $node->name,
                appName: null,
                workspaceName: null,
            );
        } catch (Throwable $exception) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $node->name,
                'remove',
                1,
                $exception->getMessage(),
            );
        }

        if (! $context->ownerProcesses()->where('name', $spec['name'])->exists()) {
            return null;
        }

        try {
            $this->removeProcess->handle($context, $spec['name']);
        } catch (GatewayApiException $exception) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $node->name,
                'remove',
                1,
                $exception->getMessage(),
            );
        }

        return [
            'name' => $spec['name'],
            'runtime' => $spec['runtime'],
            'tool' => $spec['tool'],
            'action' => 'removed',
        ];
    }
}
