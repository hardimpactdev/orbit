<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Called only by the shared private source with its authenticated participant Node. */
final readonly class DatabaseDestroyOwnedMaterial
{
    public function resolve(Operation $operation, int $authenticatedNodeId): OperationSecretValue
    {
        $serverIds = [];
        foreach ($this->servers($operation) as $server) {
            if (($server['node_id'] ?? null) === $authenticatedNodeId) {
                $serverIds[] = ValidatedData::integer($server['server_id']);
            }
        }
        if (! in_array($operation->kind, ['database.destroy_owned', 'instance.destroy'], true) || $serverIds === []
            || ! in_array($authenticatedNodeId, ValidatedData::integerList($operation->input['participant_node_ids'] ?? []), true)) {
            $this->forbidden();
        }
        $intent = DatabaseExecutionIntent::recorded(app(DatabaseIntentSource::class)->ownerId($operation));
        if ($intent->target_type !== $operation->target_type || $intent->target_id !== $operation->target_id || $intent->node_id !== $operation->node_id) {
            $this->forbidden();
        }
        $map = $intent->payload['commands']['database-apply']['input']['participant_passwords'] ?? [];
        if (! is_array($map) || ! is_array($map[$authenticatedNodeId] ?? null)) {
            $this->forbidden();
        }
        $recorded = $map[$authenticatedNodeId];
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

    /** @return list<array<string, mixed>> */
    private function servers(Operation $operation): array
    {
        $servers = $operation->kind === 'instance.destroy'
            ? Arr::get($operation->input, 'direct_destroy.database.servers', [])
            : ($operation->input['servers'] ?? []);
        if (! is_array($servers) || ! array_is_list($servers)) {
            $this->forbidden();
        }

        return array_map(static fn (mixed $server): array => ValidatedData::object($server), $servers);
    }

    private function forbidden(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'No database credentials are admitted for this participant.', 403);
    }
}
