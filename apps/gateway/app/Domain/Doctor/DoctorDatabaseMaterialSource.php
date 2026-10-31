<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\ValkeyAcl;
use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Arr;

/** Existing database credentials, scoped to the authenticated admitted Doctor worker. */
final readonly class DoctorDatabaseMaterialSource implements OperationSecretSource
{
    public function __construct(private DirectOperationPrivateBindings $bindings) {}

    public function name(): string
    {
        return 'doctor.database_servers';
    }

    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'doctor.inspect' && $operation->target_type === 'node' && (int) $operation->target_id === $operation->node_id
            ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $caller = request()->getUserResolver()();
        if (! $caller instanceof Node || $id !== $this->ownerId($operation) || $parameter !== 'database_servers') {
            $this->refuse();
        }
        $this->bindings->readable($operation, ['database_servers' => $this->name()], $caller->id);
        if ($this->bindings->ownerNode($operation, 'database_servers') !== $operation->node_id) {
            $this->refuse();
        }
        $nodes = Arr::array($operation->input, 'nodes');
        $admitted = array_values(array_filter($nodes, static fn (mixed $node): bool => is_array($node) && ($node['id'] ?? null) === $caller->id));
        if (count($admitted) !== 1) {
            $this->refuse();
        }
        $rows = Arr::get($admitted[0], 'resources.database_servers', []);
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->refuse();
        }
        $ids = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] < 1) {
                $this->refuse();
            }
            $ids[] = $row['id'];
        }
        $servers = DatabaseServer::query()->whereKey($ids)->with('databaseConnections.users')->get();
        if ($servers->count() !== count(array_unique($ids)) || $servers->contains(static fn (DatabaseServer $server): bool => $server->node_id !== $caller->id)) {
            $this->refuse();
        }
        $material = [];
        foreach ($servers as $server) {
            $material[(string) $server->id] = [
                'password' => $server->root_password,
                'acl_users' => $server->driver === DatabaseDriver::Redis
                    ? array_values(array_map(static fn (ValkeyAclUser $user): array => ['name' => $user->name, 'rules' => $user->rules, 'hashes' => $user->passwordHashes], ValkeyAcl::users($server)))
                    : [],
            ];
        }

        return new OperationSecretValue($material);
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'Database material must belong to the admitted Doctor worker.', 422);
    }
}
