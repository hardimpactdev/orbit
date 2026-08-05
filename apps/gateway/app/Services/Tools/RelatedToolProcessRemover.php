<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Actions\Processes\EditProcess;
use App\Actions\Processes\RemoveProcess;
use App\Actions\Processes\RestartProcesses;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessOwnerContextResolver;
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

/**
 * Symmetric lifecycle for tools that declare relatedProcess(): remove,
 * reconcile, or restart the matching process unit (name + tool) around tool
 * scripts.
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class RelatedToolProcessRemover
{
    public function __construct(
        private ToolCatalog $catalog,
        private ProcessOwnerContextResolver $processContexts,
        private RemoveProcess $removeProcess,
        private RestartProcesses $restartProcesses,
        private EditProcess $editProcess,
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
     * Reconcile managed process intent to the catalog's current relatedProcess()
     * command/runtime, then restart so file/env changes take effect.
     *
     * @return array{name: string, runtime: string, tool: string, action: string, command_reconciled?: bool}|ToolRegistryFailure|null
     */
    public function restartIfPresent(Node $node, string $tool): array|ToolRegistryFailure|null
    {
        $spec = $this->catalog->relatedProcess($tool);

        if ($spec === null) {
            return null;
        }

        $resolved = $this->resolveOwnedProcess($node, $tool, $spec['name'], $spec['tool'], 'reconfigure');

        if ($resolved instanceof ToolRegistryFailure || $resolved === null) {
            return $resolved;
        }

        [$context, $process] = $resolved;
        $commandReconciled = $this->reconcileProcessIntent($node, $tool, $context, $process, $spec);

        if ($commandReconciled instanceof ToolRegistryFailure) {
            return $commandReconciled;
        }

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

        return $this->restartedPayload($spec, $commandReconciled);
    }

    /**
     * @param  array{name: string, command: string, runtime: string, tool: string}  $spec
     * @return array{name: string, runtime: string, tool: string, action: string, command_reconciled?: bool}
     */
    private function restartedPayload(array $spec, bool $commandReconciled): array
    {
        $payload = [
            'name' => $spec['name'],
            'runtime' => $spec['runtime'],
            'tool' => $spec['tool'],
            'action' => 'restarted',
        ];

        if ($commandReconciled) {
            $payload['command_reconciled'] = true;
        }

        return $payload;
    }

    /**
     * @param  array{name: string, command: string, runtime: string, tool: string}  $spec
     */
    private function reconcileProcessIntent(
        Node $node,
        string $tool,
        ProcessOwnerContext $context,
        Process $process,
        array $spec,
    ): bool|ToolRegistryFailure {
        $changes = $this->relatedProcessChanges($process, $spec);

        if ($changes === []) {
            return false;
        }

        try {
            $this->editProcess->handle(
                context: $context,
                name: $spec['name'],
                changes: $changes,
                restart: false,
            );
        } catch (GatewayApiException $exception) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $node->name,
                'reconfigure',
                1,
                $exception->getMessage(),
            );
        }

        return array_key_exists('command', $changes);
    }

    /**
     * @param  array{name: string, command: string, runtime: string, tool: string}  $spec
     * @return array{command?: string, runtime?: ProcessRuntime}
     */
    private function relatedProcessChanges(Process $process, array $spec): array
    {
        $changes = [];

        if ($process->command !== $spec['command']) {
            $changes['command'] = $spec['command'];
        }

        $desiredRuntime = ProcessRuntime::tryFrom($spec['runtime']);

        if ($desiredRuntime instanceof ProcessRuntime && $process->runtime !== $desiredRuntime) {
            $changes['runtime'] = $desiredRuntime;
        }

        return $changes;
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
