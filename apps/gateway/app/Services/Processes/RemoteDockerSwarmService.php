<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RunsInternalCommands;

final readonly class RemoteDockerSwarmService
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
    ) {}

    public function remove(Node $node, string $service): bool
    {
        return $this->run($node, 'remove', $service)->successful();
    }

    public function ensureManager(Node $node): bool
    {
        return $this->run($node, 'ensure', 'orbit-runtime')->successful();
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public function apply(Node $node, string $service, array $spec): bool
    {
        return $this->run(
            node: $node,
            action: 'apply',
            service: $service,
            input: json_encode($spec, JSON_THROW_ON_ERROR),
            timeout: 120,
        )->successful();
    }

    public function restart(Node $node, string $service): bool
    {
        return $this->run($node, 'restart', $service)->successful();
    }

    public function start(Node $node, string $service): bool
    {
        return $this->run($node, 'start', $service)->successful();
    }

    public function stop(Node $node, string $service): bool
    {
        return $this->run($node, 'stop', $service)->successful();
    }

    private function run(
        Node $node,
        string $action,
        string $service,
        ?string $input = null,
        int $timeout = 60,
    ): RemoteShellResult {
        $transportOptions = [
            'metadata' => [
                'ORBIT_OPERATION_ID' => "process.docker-swarm.{$action}",
            ],
            'timeout' => $timeout,
        ];

        if ($input !== null) {
            $transportOptions['input'] = $input;
        }

        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:process-docker-swarm-service',
            arguments: [$action, $service],
            transportOptions: $transportOptions,
        );
    }
}
