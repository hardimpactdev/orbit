<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;

/** Called only by the shared private source with its authenticated participant Node. */
final readonly class DatabaseDestroyOwnedMaterial
{
    public function resolve(Operation $operation, int $authenticatedNodeId): OperationSecretValue
    {
        $serverIds = [];
        foreach ($operation->input['servers'] ?? [] as $server) {
            if (($server['node_id'] ?? null) === $authenticatedNodeId) {
                $serverIds[] = $server['server_id'];
            }
        }
        if ($operation->kind !== 'database.destroy_owned' || $serverIds === []
            || ! in_array($authenticatedNodeId, $operation->input['participant_node_ids'] ?? [], true)) {
            $this->forbidden();
        }
        $intent = DatabaseExecutionIntent::recorded($operation->input['intent_id'] ?? null);
        if ($intent->target_type !== $operation->target_type || $intent->target_id !== $operation->target_id || $intent->node_id !== $operation->node_id) {
            $this->forbidden();
        }
        $recorded = $intent->payload['commands']['database-apply']['input']['participant_passwords'][$authenticatedNodeId] ?? [];
        $passwords = [];
        foreach ($serverIds as $id) {
            $password = $recorded[$id] ?? null;
            if (! is_string($password)) {
                $this->forbidden();
            }
            $passwords[$id] = $password;
        }

        return new OperationSecretValue(['passwords' => (object) $passwords], array_values($passwords));
    }

    private function forbidden(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'No database credentials are admitted for this participant.', 403);
    }
}
