<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\RemoteShell\GatewayHostExecution;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;
use Orbit\Core\Enums\InternalCommand;

final readonly class ToolScriptDispatcher
{
    private const int DEFAULT_TIMEOUT = 900;

    private const int TIMEOUT_PADDING = 15;

    public function __construct(
        private RunsInternalCommands $localExecutor,
    ) {}

    public function run(
        Node $node,
        string $tool,
        string $action,
        string $script,
        bool $throw = false,
    ): RemoteShellResult {
        $result = $this->localExecutor->runInternal(
            node: $node,
            commandName: InternalCommand::ToolRunScript->value,
            transportOptions: [
                'input' => json_encode([
                    'tool' => $tool,
                    'action' => $action,
                    'script' => $script,
                ], JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => "tool.{$action}",
                ],
                // Tool capability and host scripts must leave the containerized
                // gateway runtime when doctor is running inside orbit-gateway.
                'force_remote_host' => GatewayHostExecution::shouldForceRemoteHost(),
                'strict' => false,
                'timeout' => self::DEFAULT_TIMEOUT + self::TIMEOUT_PADDING,
                'bind_input' => true,
                'throw' => $throw,
            ],
        );

        if (! $result->successful()) {
            return $result;
        }

        $scriptData = $this->scriptResultData(RemoteShellSuccessData::fromJsonEnvelope($result));
        $scriptResult = $this->fromSuccessEnvelope($result, $scriptData);

        if ($throw && $scriptData !== null && ! $scriptResult->successful()) {
            throw new RemoteShellFailed($node, "{$tool}:{$action}", $scriptResult);
        }

        return $scriptResult;
    }

    public function runForRegistry(
        Node $node,
        string $tool,
        string $action,
        string $script,
        bool $throw = false,
    ): RemoteShellResult|ToolRegistryFailure {
        try {
            return $this->run(
                node: $node,
                tool: $tool,
                action: $action,
                script: $script,
                throw: $throw,
            );
        } catch (RemoteLocalExecutorTransportFailed $exception) {
            return $this->agentUnreachable($node, $tool, $action, $exception);
        }
    }

    public function agentUnreachable(
        Node $node,
        string $tool,
        string $action,
        RemoteLocalExecutorTransportFailed $exception,
    ): ToolRegistryFailure {
        return ToolRegistryFailure::agentUnreachable(
            message: "Orbit Agent is unreachable for tool '{$tool}' {$action} on node '{$node->name}'.",
            meta: [
                'reason' => 'agent_push_unavailable',
                'node' => $node->name,
                'tool' => $tool,
                'action' => $action,
                'error' => $exception->getMessage(),
            ],
        );
    }

    /**
     * @param  array{exit_code: int, stdout: string, stderr: string, duration_ms: int}|null  $data
     */
    private function fromSuccessEnvelope(RemoteShellResult $result, ?array $data): RemoteShellResult
    {
        if ($data === null) {
            return new RemoteShellResult(
                exitCode: 1,
                stdout: $result->stdout,
                stderr: 'Tool run response is invalid.',
                durationMs: $result->durationMs,
            );
        }

        return new RemoteShellResult(
            exitCode: $data['exit_code'],
            stdout: $data['stdout'],
            stderr: $data['stderr'],
            durationMs: $data['duration_ms'],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{exit_code: int, stdout: string, stderr: string, duration_ms: int}|null
     */
    private function scriptResultData(array $data): ?array
    {
        $exitCode = $data['exit_code'] ?? null;
        $stdout = $data['stdout'] ?? null;
        $stderr = $data['stderr'] ?? null;
        $durationMs = $data['duration_ms'] ?? null;

        if (
            ! is_int($exitCode)
            || ! is_string($stdout)
            || ! is_string($stderr)
            || ! is_int($durationMs)
        ) {
            return null;
        }

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'duration_ms' => $durationMs,
        ];
    }
}
