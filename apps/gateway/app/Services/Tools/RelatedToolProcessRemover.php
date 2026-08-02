<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Actions\Processes\RemoveProcess;
use App\Models\Node;
use App\Services\Processes\ProcessOwnerContextResolver;
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

/**
 * Symmetric cleanup for tools that declare a relatedProcess(): remove the
 * process unit and intent row before tool binary/home teardown.
 */
final readonly class RelatedToolProcessRemover
{
    public function __construct(
        private ToolCatalog $catalog,
        private ProcessOwnerContextResolver $processContexts,
        private RemoveProcess $removeProcess,
    ) {}

    /**
     * @return array{name: string, runtime: string, tool: string, action: string}|ToolRegistryFailure|null
     */
    public function removeIfPresent(Node $node, string $tool): array|ToolRegistryFailure|null
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
