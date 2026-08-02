<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Actions\Processes\RemoveProcess;
use App\Actions\Processes\RestartProcesses;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessOwnerContextResolver;
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

/**
 * Symmetric lifecycle for tools that declare relatedProcess(): remove or
 * restart the matching process unit (name + tool) around tool scripts.
 */
final readonly class RelatedToolProcessRemover
{
    public function __construct(
        private ToolCatalog $catalog,
        private ProcessOwnerContextResolver $processContexts,
        private RemoveProcess $removeProcess,
        private RestartProcesses $restartProcesses,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     runtime: string,
     *     tool: string,
     *     action: string,
     *     warnings?: list<array<string, mixed>>
     * }|ToolRegistryFailure|null
     */
    public function removeIfPresent(Node $node, string $tool): array|ToolRegistryFailure|null
    {
        $spec = $this->catalog->relatedProcess($tool);

        if ($spec === null) {
            return null;
        }

        $resolved = $this->resolveOwnedProcess($node, $tool, $spec['name'], $spec['tool'], 'remove');

        if ($resolved instanceof ToolRegistryFailure) {
            return $resolved;
        }

        if ($resolved === null) {
            return null;
        }

        [$context] = $resolved;

        try {
            $removal = $this->removeProcess->handle($context, $spec['name']);
        } catch (GatewayApiException $exception) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $node->name,
                'remove',
                1,
                $exception->getMessage(),
            );
        }

        $payload = [
            'name' => $spec['name'],
            'runtime' => $spec['runtime'],
            'tool' => $spec['tool'],
            'action' => 'removed',
        ];

        $warnings = $removal['warnings'];

        if ($warnings !== []) {
            $payload['warnings'] = $warnings;
        }

        return $payload;
    }

    /**
     * Restart the related process after tool reconfigure so file/env changes
     * (for example Hermes public URL) take effect in the running unit.
     *
     * @return array{name: string, runtime: string, tool: string, action: string}|ToolRegistryFailure|null
     */
    public function restartIfPresent(Node $node, string $tool): array|ToolRegistryFailure|null
    {
        $spec = $this->catalog->relatedProcess($tool);

        if ($spec === null) {
            return null;
        }

        $resolved = $this->resolveOwnedProcess($node, $tool, $spec['name'], $spec['tool'], 'reconfigure');

        if ($resolved instanceof ToolRegistryFailure) {
            return $resolved;
        }

        if ($resolved === null) {
            return null;
        }

        [$context] = $resolved;
        $result = $this->restartProcesses->handle($context, $spec['name']);

        if ($result['failed']) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $node->name,
                'reconfigure',
                1,
                $result['message'],
            );
        }

        return [
            'name' => $spec['name'],
            'runtime' => $spec['runtime'],
            'tool' => $spec['tool'],
            'action' => 'restarted',
        ];
    }

    /**
     * @return array{0: ProcessOwnerContext, 1: Process}|ToolRegistryFailure|null
     */
    private function resolveOwnedProcess(
        Node $node,
        string $tool,
        string $processName,
        string $processTool,
        string $action,
    ): array|ToolRegistryFailure|null {
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
                $action,
                1,
                $exception->getMessage(),
            );
        }

        $process = $context
            ->ownerProcesses()
            ->where('name', $processName)
            ->where('tool', $processTool)
            ->first();

        if (! $process instanceof Process) {
            return null;
        }

        return [$context, $process];
    }
}
