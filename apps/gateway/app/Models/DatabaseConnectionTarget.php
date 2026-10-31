<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\DatabaseConnections\DatabaseOwnerAdmission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $database_connection_id
 * @property int $instance_id
 * @property string $network
 * @property string $prefix
 * @property-read DatabaseConnection $databaseConnection
 * @property-read Instance $instance
 */
final class DatabaseConnectionTarget extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'database_connection_id',
        'instance_id',
        'prefix',
        'network',
    ];

    /** @param array{touch?: bool|null} $options */
    #[\Override]
    public function save(array $options = []): bool
    {
        if ($this->exists && ! $this->isDirty(['database_connection_id', 'instance_id'])) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            $stored = $this->exists ? $this->newQuery()->find($this->id) : null;
            $ids = array_filter([$stored?->database_connection_id, $this->database_connection_id]);
            foreach (DatabaseConnection::on($this->getConnectionName())->whereKey($ids)->get() as $connection) {
                app(DatabaseOwnerAdmission::class)->assertWritable($connection);
            }

            return parent::save($options);
        });
    }

    /** @return BelongsTo<DatabaseConnection, $this> */
    public function databaseConnection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class);
    }

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_id');
    }
}
