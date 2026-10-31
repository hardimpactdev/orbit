<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use LogicException;

/** Admission state validation only; no execution lock, lease or Node work. */
final readonly class DatabaseOwnerAdmission
{
    /** Destruction identity is immutable from removal admission through final projection. */
    public const array IdentityFields = ['owner_instance_id', 'slug', 'driver', 'node_id', 'database_server_id', 'path',
        'database', 'username', 'test_database', 'test_processes'];

    /** Called inside the same short transaction as the guarded row write. */
    public function assertWritable(DatabaseConnection $pending): void
    {
        if ($pending->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Database ownership admission requires the row write transaction.');
        }
        // A model resolved before removal or reassignment may have a stale original owner.
        $stored = $pending->exists ? $pending->newQuery()->find($pending->id) : null;
        $ids = array_values(array_unique(array_filter([$stored?->owner_instance_id, $pending->owner_instance_id], static fn (?int $id): bool => $id !== null)));
        $owners = Instance::on($pending->getConnectionName())->whereKey($ids)->orderBy('id')->get();
        if ($owners->contains(static fn (Instance $owner): bool => $owner->status === InstanceState::Removing)) {
            throw new ResourceOperationException('database.owner_removing', 'Database ownership and identity cannot change while either owner is Removing.', 409);
        }
        if ($owners->count() !== count($ids)) {
            throw new ResourceOperationException('database.owner_unavailable', 'The database owner is no longer available.', 409);
        }
    }
}
