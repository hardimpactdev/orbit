<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Destructive MySQL work requires durable creation or operator ownership evidence. */
final readonly class DatabaseOwnership
{
    /** @param list<string> $candidates
     * @return list<string>
     */
    public function mysqlNames(DatabaseConnection $connection, array $candidates = []): array
    {
        ['names' => $names, 'prefixes' => $prefixes] = $this->mysqlOwnership($connection);
        foreach ($candidates as $name) {
            if (array_any($prefixes, static fn (string $prefix): bool => str_starts_with($name, $prefix))) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    public function ownsMysqlPrefix(DatabaseConnection $connection, string $prefix): bool
    {
        return in_array($prefix, $this->mysqlOwnership($connection)['prefixes'], true);
    }

    /** @return array{names:list<string>,prefixes:list<string>} */
    private function mysqlOwnership(DatabaseConnection $connection): array
    {
        $names = [];
        $prefixes = [];
        foreach ($connection->creation_receipts ?? [] as $proof) {
            if (! $this->proven($connection, $proof)) {
                continue;
            }
            if (($proof['scope'] ?? null) === 'prefix') {
                if ($proof['database'] === $connection->test_database) {
                    $prefixes[] = $proof['database'];
                }
            } else {
                $names[] = $proof['database'];
            }
        }

        $adoption = $connection->ownership_adoption;
        if ($adoption !== null && $adoption['username'] === $connection->username
            && $adoption['server_id'] === $connection->database_server_id && $adoption['node_id'] === $connection->server?->node_id) {
            $names = [...$names, ...$adoption['databases']];
            if ($adoption['prefix'] !== null && $adoption['prefix'] === $connection->test_database) {
                $prefixes[] = $adoption['prefix'];
            }
        }

        return ['names' => $names, 'prefixes' => $prefixes];
    }

    /** @param array{database:string,username:string,server_id:int,scope?:'prefix',activity_id?:int,operation_id?:int,step?:string} $proof */
    private function proven(DatabaseConnection $connection, array $proof): bool
    {
        if ($proof['username'] !== $connection->username || $proof['server_id'] !== $connection->database_server_id) {
            return false;
        }
        if (isset($proof['activity_id'])) {
            $activity = Activity::query()->find($proof['activity_id']);
            $properties = $activity?->properties?->toArray() ?? [];
            unset($proof['activity_id']);

            return $activity?->command === 'database:lifecycle:create' && $activity->status === 'succeeded'
                && $activity->subject_type === DatabaseConnection::class && $activity->subject_id === $connection->id
                && ($properties['connection_id'] ?? null) === $connection->id
                && ($properties['node_id'] ?? null) === $connection->server?->node_id && ($properties['receipt'] ?? null) === $proof;
        }
        $operation = Operation::query()->find($proof['operation_id'] ?? null);
        $run = $operation === null ? [] : ValidatedData::object(ValidatedData::object($operation->state['executor_runs'] ?? [])[$proof['step'] ?? ''] ?? []);

        return $operation !== null && in_array($operation->kind, ['database.create', 'database.clone'], true)
            && ($operation->input['connection_id'] ?? null) === $connection->id && $operation->node_id === $connection->server?->node_id
            && ($run['transport'] ?? null) === 'agent' && ($run['name'] ?? null) === 'database.server-admin'
            && ($run['result'] ?? null) === ['backend' => 'active'];
    }

    /** @param list<string> $names */
    public function assertMysqlNames(DatabaseConnection $connection, array $names): void
    {
        if (array_diff($names, $this->mysqlNames($connection, $names)) !== []) {
            throw new ResourceOperationException('database.ownership_unproven', 'Database removal requires a successful creation receipt or operator adoption proof for every name.', 409);
        }
    }
}
