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
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

/**
 * Removal-only migration cleanup for OpenClaw after first-party catalog support
 * was withdrawn. Does not reintroduce install/update/reconfigure/credentials.
 *
 * A successful Orbit process-unit removal can still leave a detached listener on
 * the historical OpenClaw port when the unit stop does not reap the runtime, or
 * when a non-Orbit unit/process keeps the port. This cleanup is the typed path
 * that stops listeners, drops legacy units, deletes agent home state, and clears
 * gateway process/proxy/tool intent.
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class LegacyOpenClawRuntimeCleanup
{
    public const string TOOL = 'openclaw';

    public const string PROCESS_NAME = 'openclaw-gateway';

    public const int LISTEN_PORT = 18_789;

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
        return $tool === self::TOOL;
    }

    /**
     * Host cleanup script for detached/orphan OpenClaw runtime residue.
     *
     * Best-effort stop/kill steps ignore missing targets, but final success is
     * verified: port listeners, residual openclaw processes, and agent home
     * state must be gone.
     *
     * Security-sensitive targets (user, home path, port, kill wait) are
     * hard-coded literals in the generated script. They must not be read from
     * the process environment: Agent script execution inherits the parent env
     * and must not redirect privileged kills or home teardown via overrides.
     * Test harnesses rewrite the generated text in-process only.
     */
    public function cleanupScript(): string
    {
        $port = self::LISTEN_PORT;
        $processUnit = self::PROCESS_NAME.'.service';

        return <<<BASH
            #!/usr/bin/env bash
            # orbit legacy-remove openclaw (removal-only migration; not product support)
            set -u
            # Fixed targets — never env-overridable (Agent inherits parent env).
            OPENCLAW_HOME='/home/agent/.openclaw'
            OPENCLAW_USER='agent'
            OPENCLAW_PORT='{$port}'
            KILL_WAIT=1
            # Privileged ss is required so orbit can read agent-owned PIDs.
            listener_pids() {
              sudo ss -lptn "sport = :\${OPENCLAW_PORT}" 2>/dev/null \\
                | sed -n 's/.*pid=\\([0-9][0-9]*\\).*/\\1/p' \\
                | sort -u
            }
            openclaw_process_present() {
              sudo pgrep -u "\${OPENCLAW_USER}" -f "\${OPENCLAW_HOME}/bin/openclaw" >/dev/null 2>&1 \\
                || sudo pgrep -u "\${OPENCLAW_USER}" -f 'openclaw gateway' >/dev/null 2>&1
            }
            stop_unit() {
              local unit="\$1"
              sudo systemctl stop "\${unit}" >/dev/null 2>&1 || true
              sudo systemctl disable "\${unit}" >/dev/null 2>&1 || true
              sudo rm -f "/etc/systemd/system/\${unit}" "/lib/systemd/system/\${unit}" "/usr/lib/systemd/system/\${unit}"
              sudo systemctl reset-failed "\${unit}" >/dev/null 2>&1 || true
            }
            stop_unit '{$processUnit}'
            stop_unit 'openclaw.service'
            # Agent-scoped user units OpenClaw may have enabled historically.
            sudo -u "\${OPENCLAW_USER}" -H bash -lc 'systemctl --user stop openclaw-gateway.service openclaw.service >/dev/null 2>&1 || true' || true
            sudo -u "\${OPENCLAW_USER}" -H bash -lc 'systemctl --user disable openclaw-gateway.service openclaw.service >/dev/null 2>&1 || true' || true
            sudo systemctl daemon-reload >/dev/null 2>&1 || true
            # Terminate anything still listening on the historical OpenClaw port.
            for pid in \$(listener_pids); do
              sudo kill -TERM "\${pid}" >/dev/null 2>&1 || true
            done
            sleep "\${KILL_WAIT}"
            for pid in \$(listener_pids); do
              sudo kill -KILL "\${pid}" >/dev/null 2>&1 || true
            done
            # Residual agent-owned OpenClaw binaries/commands not under systemd.
            sudo pkill -u "\${OPENCLAW_USER}" -f "\${OPENCLAW_HOME}/bin/openclaw" >/dev/null 2>&1 || true
            sudo pkill -u "\${OPENCLAW_USER}" -f 'openclaw gateway' >/dev/null 2>&1 || true
            # Fixed agent home/state teardown (path is hard-coded above).
            sudo rm -rf "\${OPENCLAW_HOME}" 2>/dev/null || true
            # Optional world shim from older install paths (may be absent).
            sudo rm -f /usr/local/bin/openclaw 2>/dev/null || true
            # Verified success: refuse false-positive exit 0 when residue remains.
            remaining_pids="\$(listener_pids | tr '\\n' ' ' | sed 's/[[:space:]]*\$//')"
            if [ -n "\${remaining_pids}" ]; then
              echo "legacy openclaw cleanup incomplete: port \${OPENCLAW_PORT} still listening (pids: \${remaining_pids})" >&2
              exit 1
            fi
            if openclaw_process_present; then
              echo "legacy openclaw cleanup incomplete: openclaw process still running for user \${OPENCLAW_USER}" >&2
              exit 1
            fi
            if [ -e "\${OPENCLAW_HOME}" ]; then
              echo "legacy openclaw cleanup incomplete: \${OPENCLAW_HOME} still exists" >&2
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
                'Unable to resolve a tool-host node for legacy OpenClaw removal.',
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

        $routesRemoved = $this->staleIntentRemover->removeOwnedProxyRoutesFor(self::TOOL, $target);
        $toolRowRemoved = $this->deleteToolRowIfPresent($target);

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
            ->where('name', self::PROCESS_NAME)
            ->where('tool', self::TOOL)
            ->first();

        if (! $process instanceof Process) {
            $process = $context
                ->ownerProcesses()
                ->where('name', self::PROCESS_NAME)
                ->first();
        }

        if (! $process instanceof Process) {
            $process = $context
                ->ownerProcesses()
                ->where('tool', self::TOOL)
                ->first();
        }

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

    private function deleteToolRowIfPresent(Node $node): bool
    {
        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', self::TOOL)
            ->first();

        if (! $tool instanceof NodeTool) {
            return false;
        }

        $tool->credentials = null;
        $tool->save();
        $tool->delete();

        return true;
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
