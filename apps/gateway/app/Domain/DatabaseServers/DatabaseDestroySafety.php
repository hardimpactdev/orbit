<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

final readonly class DatabaseDestroySafety
{
    private const array SYSTEM_DATABASES = ['mysql', 'sys', 'performance_schema', 'information_schema', 'postgres'];

    /** @param list<string> $names
     * @param  list<string>  $prefixes
     */
    public function assertNames(array $names, array $prefixes = []): void
    {
        foreach ($names as $name) {
            $name = strtolower($name);
            if (in_array($name, self::SYSTEM_DATABASES, true) || str_starts_with($name, 'template')) {
                $this->refuseSystemDatabase();
            }
        }
        foreach ($prefixes as $prefix) {
            $prefix = strtolower($prefix);
            foreach ([...self::SYSTEM_DATABASES, 'template'] as $system) {
                if (str_starts_with($system, $prefix) || str_starts_with($prefix, 'template')) {
                    $this->refuseSystemDatabase();
                }
            }
        }
    }

    /** @param list<string> $databases
     * @param  list<string>  $users
     */
    public function assertAcceptsCreates(DatabaseServer $server, array $databases = [], array $users = []): void
    {
        $operations = Operation::query()->whereIn('kind', ['database.destroy', 'instance.destroy', 'instance.restore'])
            ->whereNotIn('status', OperationStatus::terminalValues())->get();
        foreach ($operations as $operation) {
            $direct = $operation->target_type === 'database_server' && $operation->target_id === $server->id;
            $servers = match ($operation->kind) {
                'instance.destroy' => Arr::get($operation->input, 'direct_destroy.database.servers', []),
                'instance.restore' => $operation->input['servers'] ?? [],
                default => [],
            };
            $owned = is_array($servers) && array_any($servers, static function (mixed $entry) use ($server, $operation, $databases, $users): bool {
                if (! is_array($entry) || ($entry['server_id'] ?? null) !== $server->id) {
                    return false;
                }
                if ($operation->kind !== 'instance.restore') {
                    return true;
                }

                return array_intersect($databases, ValidatedData::stringList(array_column(RouteExecutionProof::objects($entry['databases']), 'name'))) !== []
                    || array_intersect($users, ValidatedData::stringList(array_column(RouteExecutionProof::objects($entry['users']), 'name'))) !== [];
            });
            if ($direct || $owned) {
                throw new ResourceOperationException('database.server_in_use', 'Database removal is unfinished on this server.', 409);
            }
        }
    }

    /** @param list<string> $databases
     * @param  list<string>  $users
     */
    public function assertAcceptsRestore(DatabaseServer $server, array $databases, array $users): void
    {
        $operations = Operation::query()->whereIn('kind', ['database.create', 'database_user.create'])
            ->where('target_type', 'database_server')->where('target_id', $server->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->get();
        foreach ($operations as $operation) {
            $intent = DatabaseExecutionIntent::query()->whereKey($operation->input['intent_id'] ?? null)->first();
            $safe = $intent?->payload['commands']['database-apply']['input'] ?? null;
            if (! is_array($safe)) {
                throw new ResourceOperationException('instance.restore_busy', 'A database create has incomplete admission evidence.', 409, details: ['reason' => 'busy']);
            }
            $creatingDatabases = $operation->kind === 'database.create'
                ? ValidatedData::stringList($safe['databases']) : [ValidatedData::string($safe['database'])];
            $creatingUsers = $operation->kind === 'database.create'
                ? [ValidatedData::string($safe['username'])]
                : [ValidatedData::string($safe['username']), ValidatedData::string($safe['owner_username'])];
            if (array_intersect($databases, $creatingDatabases) !== [] || array_intersect($users, $creatingUsers) !== []) {
                throw new ResourceOperationException('instance.restore_busy', 'Database or user creation still owns names in this removal.', 409,
                    details: ['reason' => 'busy', 'databases' => array_values(array_intersect($databases, $creatingDatabases)),
                        'users' => array_values(array_intersect($users, $creatingUsers))]);
            }
        }
    }

    public function assertAcceptsRemoval(DatabaseServer $server): void
    {
        if (Operation::query()->whereIn('kind', ['database.create', 'database_user.create'])
            ->where('target_type', 'database_server')->where('target_id', $server->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
            throw new ResourceOperationException('database.server_in_use', 'Database creation is unfinished on this server.', 409);
        }
    }

    private function refuseSystemDatabase(): never
    {
        throw new ResourceOperationException('database.system_database', 'System databases and prefixes cannot be removed.', 422);
    }
}
