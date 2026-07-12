<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\Node;
use App\Models\Process as ProcessModel;
use Illuminate\Support\Facades\Process;

final readonly class ToolLifecycleManager
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRuntimeResolver $runtimes,
        private ToolScriptDispatcher $toolScriptDispatcher,
        private ProcessToolLifecycleRunner $processLifecycle,
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
        $target = $this->runtimes->resolve($tool, $action, $node, $app);

        if ($target instanceof ToolRegistryFailure) {
            return $target;
        }

        if ($target->process instanceof ProcessModel) {
            return $this->processLifecycle->run($target, $action);
        }

        $config = is_array($target->tool->config) ? $target->tool->config : [];
        $script = $this->catalog->lifecycleScript($tool, $action, $config);

        if ($script === null) {
            return ToolRegistryFailure::unsupportedAction($tool, $action);
        }

        $result = $this->runToolOwnedScript($target->node, $tool, $action, $script);

        if ($result instanceof ToolRegistryFailure) {
            return $result;
        }

        return [
            'name' => $tool,
            'node' => $target->node->name,
            'action' => $action,
            'runtime' => 'tool',
        ];
    }

    private function runToolOwnedScript(
        Node $node,
        string $tool,
        string $action,
        string $script,
    ): true|ToolRegistryFailure {
        if ($this->catalog->gatewayLocal($tool)) {
            $result = Process::timeout(900)->run($script);

            if (! $result->successful()) {
                return ToolRegistryFailure::remoteActionFailed(
                    $tool,
                    $node->name,
                    $action,
                    $result->exitCode() ?? 1,
                    trim($result->errorOutput()),
                );
            }

            return true;
        }

        $result = $this->toolScriptDispatcher->runForRegistry(
            node: $node,
            tool: $tool,
            action: $action,
            script: $script,
        );

        if ($result instanceof ToolRegistryFailure) {
            return $result;
        }

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed(
                tool: $tool,
                node: $node->name,
                action: $action,
                exitCode: $result->exitCode,
                stderr: trim($result->stderr),
            );
        }

        return true;
    }
}
