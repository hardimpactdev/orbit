<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;
use JsonException;

final readonly class RemoteRuntimeHibernation
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
    ) {}

    public function markAwake(Node $node, string $key): RemoteShellResult
    {
        return $this->run($node, 'runtime-awake', ['key' => $key]);
    }

    public function markAsleep(Node $node, string $key): RemoteShellResult
    {
        return $this->run($node, 'runtime-asleep', ['key' => $key]);
    }

    public function markCold(Node $node, string $key): RemoteShellResult
    {
        return $this->run($node, 'runtime-cold', ['key' => $key]);
    }

    public function markWarm(Node $node, string $key): RemoteShellResult
    {
        return $this->run($node, 'runtime-warm', ['key' => $key]);
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{key: string, awake: bool, hibernated: bool, cold: bool, last_activity_at: int|null}>|null
     */
    public function states(Node $node, array $keys): ?array
    {
        $result = $this->run($node, 'runtime-states', ['keys' => $keys]);

        if (! $result->successful()) {
            return null;
        }

        try {
            $data = RemoteShellSuccessData::fromJsonEnvelope($result);
        } catch (JsonException) {
            return null;
        }

        $rawStates = $data['states'] ?? null;

        if (! is_array($rawStates)) {
            return null;
        }

        $states = [];

        foreach ($rawStates as $rawState) {
            $state = RuntimeHibernationState::from($rawState);

            if (! $state instanceof RuntimeHibernationState) {
                continue;
            }

            $states[] = $state->toArray();
        }

        return $states;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function run(Node $node, string $action, array $payload): RemoteShellResult
    {
        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:caddy-config',
            arguments: [$action],
            transportOptions: [
                'input' => json_encode($payload, JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => "caddy-config.{$action}",
                ],
                'redact_stdout' => true,
                'redact_stderr' => false,
                'timeout' => 30,
                'throw' => false,
            ],
        );
    }
}
