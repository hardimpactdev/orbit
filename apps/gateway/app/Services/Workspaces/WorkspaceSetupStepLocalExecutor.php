<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RemoteShellSuccessData;
use Throwable;

final readonly class WorkspaceSetupStepLocalExecutor
{
    public function __construct(
        private ?RemoteLocalExecutor $localExecutor,
    ) {}

    /**
     * @param  array<string, string>  $environment
     */
    public function run(Node $node, string $command, ?string $cwd, int $timeout, array $environment): RemoteShellResult
    {
        if (! $this->localExecutor instanceof RemoteLocalExecutor || ! $node->orbit_agent_capable) {
            return $this->failure(
                'workspace:setup-step requires an Orbit Agent capable node or explicit --node-transport=transitional-ssh-fallback.',
            );
        }

        try {
            $result = $this->localExecutor->runInternal(
                node: $node,
                commandName: 'internal:workspace-setup-step',
                transportOptions: [
                    'throw' => false,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => 'workspace-setup-step',
                    ],
                    'input' => json_encode([
                        'command' => $command,
                        'cwd' => $cwd,
                        'timeout' => $timeout,
                        'environment' => $environment,
                    ], JSON_THROW_ON_ERROR),
                    'strict' => false,
                    'timeout' => $timeout + 15,
                ],
            );
        } catch (Throwable $throwable) {
            return $this->failure($throwable->getMessage());
        }

        if (! $result->successful()) {
            return $result;
        }

        return $this->fromSuccessEnvelope($result);
    }

    private function fromSuccessEnvelope(RemoteShellResult $result): RemoteShellResult
    {
        $data = RemoteShellSuccessData::fromJsonEnvelope($result);

        if (
            ! is_int($data['exit_code'] ?? null)
            || ! is_string($data['stdout'] ?? null)
            || ! is_string($data['stderr'] ?? null)
            || ! is_int($data['duration_ms'] ?? null)
        ) {
            return new RemoteShellResult(
                exitCode: 1,
                stdout: $result->stdout,
                stderr: 'Workspace setup step response is invalid.',
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

    private function failure(string $message): RemoteShellResult
    {
        return new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: $message,
            durationMs: 0,
        );
    }
}
