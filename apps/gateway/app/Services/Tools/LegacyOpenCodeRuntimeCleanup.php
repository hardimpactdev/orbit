<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Actions\Processes\RemoveProcess;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Processes\ProcessOwnerContextResolver;
use Illuminate\Database\Eloquent\Builder;
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

/**
 * Removal-only migration cleanup for Orbit-managed OpenCode residue after
 * first-party catalog support was withdrawn.
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class LegacyOpenCodeRuntimeCleanup
{
    public const string TOOL = 'opencode-cli';

    public const string PROCESS_NAME = 'opencode-server';

    /**
     * @var list<string>
     */
    public const array ToolAliases = ['opencode-cli', 'opencode', 'opencode-server'];

    /**
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        private ToolAppNodeResolver $instanceNodes,
        private NodeRoleAssignments $nodeRoleAssignments,
        private ToolScriptDispatcher $toolScriptDispatcher,
        private ProcessOwnerContextResolver $processContexts,
        private RemoveProcess $removeProcess,
        private StaleToolIntentRemover $staleIntentRemover,
    ) {}

    public function applies(string $tool): bool
    {
        return in_array($tool, self::ToolAliases, true);
    }

    public function cleanupScript(): string
    {
        $processUnit = self::PROCESS_NAME.'.service';

        return <<<BASH
            #!/usr/bin/env bash
            # orbit legacy-remove opencode-cli (removal-only migration; not product support)
            set -u
            # Fixed Orbit-managed targets — never env-overridable.
            OPENCODE_HOME='/home/agent/.opencode'
            OPENCODE_USER='agent'
            stop_unit() {
              local unit="\$1"
              sudo systemctl stop "\${unit}" >/dev/null 2>&1 || true
              sudo systemctl disable "\${unit}" >/dev/null 2>&1 || true
              sudo rm -f "/etc/systemd/system/\${unit}" "/lib/systemd/system/\${unit}" "/usr/lib/systemd/system/\${unit}"
              sudo systemctl reset-failed "\${unit}" >/dev/null 2>&1 || true
            }
            stop_unit '{$processUnit}'
            sudo systemctl daemon-reload >/dev/null 2>&1 || true
            # Residual agent-owned OpenCode server processes under Orbit home.
            sudo pkill -u "\${OPENCODE_USER}" -f "\${OPENCODE_HOME}/bin/opencode" >/dev/null 2>&1 || true
            sudo pkill -u "\${OPENCODE_USER}" -f 'opencode serve' >/dev/null 2>&1 || true
            sudo rm -rf "\${OPENCODE_HOME}" 2>/dev/null || true
            if sudo pgrep -u "\${OPENCODE_USER}" -f "\${OPENCODE_HOME}/bin/opencode" >/dev/null 2>&1 \\
              || sudo pgrep -u "\${OPENCODE_USER}" -f 'opencode serve' >/dev/null 2>&1; then
              echo "legacy opencode cleanup incomplete: opencode process still running for user \${OPENCODE_USER}" >&2
              exit 1
            fi
            if [ -e "\${OPENCODE_HOME}" ]; then
              echo "legacy opencode cleanup incomplete: \${OPENCODE_HOME} still exists" >&2
              exit 1
            fi
            if [ -f "/etc/systemd/system/{$processUnit}" ] || [ -f "/lib/systemd/system/{$processUnit}" ] || [ -f "/usr/lib/systemd/system/{$processUnit}" ]; then
              echo "legacy opencode cleanup incomplete: {$processUnit} unit file still present" >&2
              exit 1
            fi
            exit 0
            BASH;
    }

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function remove(?string $node = null, ?string $app = null): array|ToolRegistryFailure
    {
        $target = $this->targetNode($node, $app);

        if (! $target instanceof Node) {
            return ToolRegistryFailure::validation(
                'target',
                $node ?? $app ?? '',
                'Unable to resolve a tool-host node for legacy OpenCode removal.',
                ['tool' => self::TOOL, 'node' => $node, 'app' => $app],
            );
        }

        $processPayload = $this->removeProcessIntentIfPresent($target);

        if ($processPayload instanceof ToolRegistryFailure) {
            return $processPayload;
        }

        $scriptFailure = $this->runCleanupScript($target);

        if ($scriptFailure instanceof ToolRegistryFailure) {
            return $scriptFailure;
        }

        $routesRemoved = 0;
        foreach (self::ToolAliases as $toolName) {
            $routesRemoved += $this->staleIntentRemover->removeOwnedProxyRoutesFor($toolName, $target);
        }
        $toolRowRemoved = $this->deleteToolRowsIfPresent($target);

        $payload = [
            'name' => self::TOOL,
            'node' => $target->name,
            'stale_record' => true,
            'legacy_runtime_cleanup' => true,
            'routes_removed' => $routesRemoved,
            'tool_row_removed' => $toolRowRemoved,
        ];

        if ($processPayload !== null) {
            $payload['process'] = $processPayload;
            $warnings = $processPayload['warnings'] ?? null;

            if (is_array($warnings) && $warnings !== []) {
                $payload['warnings'] = $warnings;
            }
        }

        return $payload;
    }

    /**
     * @return array{
     *     name: string,
     *     runtime: string,
     *     tool: string,
     *     action: string,
     *     warnings?: list<array<string, mixed>>
     * }|ToolRegistryFailure|null
     */
    private function removeProcessIntentIfPresent(Node $node): array|ToolRegistryFailure|null
    {
        try {
            $context = $this->processContexts->resolve(
                nodeName: $node->name,
                appName: null,
                workspaceName: null,
            );
        } catch (Throwable $exception) {
            return ToolRegistryFailure::remoteActionFailed(
                self::TOOL,
                $node->name,
                'remove',
                1,
                $exception->getMessage(),
            );
        }

        $process = $context
            ->ownerProcesses()
            ->where(static function (Builder $query): void {
                $query->where('name', self::PROCESS_NAME)
                    ->orWhereIn('tool', self::ToolAliases);
            })
            ->first();

        if (! $process instanceof Process) {
            return null;
        }

        try {
            $removal = $this->removeProcess->handle($context, $process->name);
        } catch (GatewayApiException $exception) {
            return ToolRegistryFailure::remoteActionFailed(
                self::TOOL,
                $node->name,
                'remove',
                1,
                $exception->getMessage(),
            );
        }

        $payload = [
            'name' => $process->name,
            'runtime' => $process->runtime->value,
            'tool' => self::TOOL,
            'action' => 'removed',
        ];

        if ($removal['warnings'] !== []) {
            $payload['warnings'] = $removal['warnings'];
        }

        return $payload;
    }

    private function runCleanupScript(Node $node): ?ToolRegistryFailure
    {
        $result = $this->toolScriptDispatcher->runForRegistry(
            node: $node,
            tool: self::TOOL,
            action: 'remove',
            script: $this->cleanupScript(),
        );

        if ($result instanceof ToolRegistryFailure) {
            return $result;
        }

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed(
                self::TOOL,
                $node->name,
                'remove',
                $result->exitCode,
                trim($result->stderr),
            );
        }

        return null;
    }

    private function deleteToolRowsIfPresent(Node $node): bool
    {
        $removed = false;

        foreach (self::ToolAliases as $toolName) {
            $tool = NodeTool::query()
                ->where('node_id', $node->id)
                ->where('name', $toolName)
                ->first();

            if (! $tool instanceof NodeTool) {
                continue;
            }

            $tool->credentials = null;
            $tool->save();
            $tool->delete();
            $removed = true;
        }

        return $removed;
    }

    private function targetNode(?string $node, ?string $app): ?Node
    {
        if ($node !== null) {
            /** @var Node|null */
            return Node::query()
                ->where('name', $node)
                ->where('status', NodeStatus::Active->value)
                ->whereIn('id', $this->nodeRoleAssignments->activeToolHostNodeIds())
                ->whereNotIn('id', $this->gatewayNodeIds())
                ->first();
        }

        if ($app === null) {
            return null;
        }

        return $this->instanceNodes->resolve($app);
    }

    /**
     * @return list<int>
     */
    private function gatewayNodeIds(): array
    {
        return array_values(
            $this->nodeRoleAssignments
                ->activeGatewayNodeQuery()
                ->pluck('id')
                ->map(static fn (mixed $nodeId): int => (int) $nodeId)
                ->all(),
        );
    }
}
