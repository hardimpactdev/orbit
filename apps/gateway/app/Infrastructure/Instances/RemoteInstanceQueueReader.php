<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\Queue\InstanceQueueReader;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Process;

final readonly class RemoteInstanceQueueReader implements InstanceQueueReader
{
    public function __construct(
        private ProcessTargetResolver $targets,
        private AgentCommandRunner $agents,
    ) {}

    public function read(Process $horizon, string $state, int $limit): array
    {
        $target = $this->targets->forInspection($horizon);
        $directory = StoragePath::tryParse($horizon->working_directory ?? $target->defaultWorkingDirectory);
        $command = $horizon->runtime_config['command'] ?? null;
        $phpBinary = is_array($command) && is_string($command[0] ?? null) ? $command[0] : '';
        $php = StoragePath::tryParse($phpBinary);

        if ($directory === null || $php === null) {
            throw $this->failed($horizon);
        }

        // The agent runs its fixed report script; the only values from the caller are the validated state and limit.
        try {
            $report = $this->agents->run($target->node, 'instances.read-queue', [
                'user' => $target->user,
                'directory' => $directory->value,
                'php' => $php->value,
                'state' => $state,
                'limit' => $limit,
            ])['report'] ?? null;
        } catch (AgentCommandRefused|AgentRunnerFailure) {
            throw $this->failed($horizon);
        }

        return is_array($report) ? $report : throw $this->failed($horizon);
    }

    private function failed(Process $horizon): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'instance.queue_failed',
            message: "The queue of Process [{$horizon->name}] could not be read.",
            status: 502,
        );
    }
}
