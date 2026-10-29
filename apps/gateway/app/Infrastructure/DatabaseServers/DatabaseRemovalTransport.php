<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseServers;

use App\Domain\DatabaseServers\DatabaseOperationExecution;
use App\Domain\DatabaseServers\DatabaseRemovalLock;
use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationTransportInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\OperationAgentTransport;
use App\Models\DatabaseConnection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Serialize the fresh ownership/login check and SQL dispatch with connection reservations. */
final readonly class DatabaseRemovalTransport implements OperationEffectTransport, OperationTransportInput
{
    public function __construct(private OperationAgentTransport $agent, private DatabaseRemovalLock $lock, private DatabaseOperationExecution $execution) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return $this->agent->schema($operation, $run);
    }

    public function wireInput(Operation $operation, array $run, #[\SensitiveParameter] array $input): mixed
    {
        return $this->agent->wireInput($operation, $run, $input);
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return $this->agent->effectful($operation, $run);
    }

    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return $this->agent->receipt($operation, $run, $input);
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $connection = DatabaseConnection::query()->findOrFail(ValidatedData::integer($operation->input['connection_id']));
        $server = $connection->server;
        if ($server === null || $server->node_id !== $operation->node_id) {
            throw new ResourceOperationException('database.server_required', 'The recorded removal server is unavailable.', 409);
        }

        return $this->lock->run([$server->id], function () use ($operation, $run, $input): array {
            $this->execution->beforeRun($operation, $run);

            return $this->agent->run($operation, $run, $input);
        });
    }
}
