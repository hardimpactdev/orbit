<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;
use Throwable;

final readonly class AppSetupStepLocalExecutor
{
    public function __construct(
        private ?RunsInternalCommands $localExecutor,
        private NodeRoleAssignments $nodeRoleAssignments = new NodeRoleAssignments,
    ) {}

    /**
     * @param  array<string, string>  $environment
     */
    public function run(Node $node, string $command, ?string $cwd, int $timeout, array $environment): RemoteShellResult
    {
        if (
            ! $this->localExecutor instanceof RunsInternalCommands
            || ! $node->isAgentEligible()
            && ! $this->nodeRoleAssignments->nodeIsGateway($node)
        ) {
            return $this->failure(
                'instance:setup-step requires an Orbit Agent capable node.',
            );
        }

        try {
            $result = $this->localExecutor->runInternal(
                node: $node,
                commandName: 'internal:app-setup-step',
                transportOptions: [
                    'throw' => false,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => 'app-setup-step',
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
                stderr: 'App setup step response is invalid.',
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
