<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseConnections\DatabaseOwnerAdmission;
use App\Domain\Instances\DatabaseClone\DatabaseCloneStep;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property list<array{database:string,operation_id?:int,step?:string,activity_id?:int,username:string,server_id:int,scope?:'prefix'}>|null $creation_receipts
 * @property array{actor:string,reason:string,verified_at:string,databases:list<string>,prefix:?string,username:string,server_id:int,node_id:int,digest:string}|null $ownership_adoption
 * @property int $id
 * @property string $slug
 * @property DatabaseDriver $driver
 * @property int|null $node_id
 * @property int|null $database_server_id
 * @property int|null $owner_instance_id
 * @property DatabaseCloneStep|null $clone_step
 * @property string|null $host
 * @property int|null $port
 * @property string|null $database
 * @property string|null $locale
 * @property int|null $test_processes
 * @property string|null $test_database
 * @property string|null $path
 * @property string|null $username
 * @property string|null $password
 * @property-read Node|null $node
 * @property-read DatabaseServer|null $server
 * @property-read Instance|null $ownerInstance
 * @property-read Collection<int, DatabaseConnectionTarget> $targets
 * @property-read Collection<int, DatabaseUser> $users
 */
final class DatabaseConnection extends Model
{
    /**
     * SQLite IMMEDIATE serializes this admission check and row write with lifecycle's prepare + Removing commit.
     * The persisted Removing state excludes ownership writes throughout the later native run.
     *
     * @param  array{touch?: bool|null}  $options
     */
    #[\Override]
    public function save(array $options = []): bool
    {
        if ($this->exists && ! $this->isDirty(DatabaseOwnerAdmission::IdentityFields)) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            app(DatabaseOwnerAdmission::class)->assertWritable($this);

            return parent::save($options);
        });
    }

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'slug',
        'driver',
        'node_id',
        'database_server_id',
        'owner_instance_id',
        'clone_step',
        'host',
        'port',
        'database',
        'locale',
        'test_database',
        'test_processes',
        'path',
        'username',
        'password',
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = [
        'password',
        'creation_receipts',
        'ownership_adoption',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return array<array-key, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<DatabaseServer, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(DatabaseServer::class, 'database_server_id');
    }

    /** @return BelongsTo<Instance, $this> */
    public function ownerInstance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'owner_instance_id');
    }

    /** @return HasMany<DatabaseConnectionTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(DatabaseConnectionTarget::class);
    }

    /** @return HasMany<DatabaseUser, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(DatabaseUser::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'driver' => DatabaseDriver::class,
            'clone_step' => DatabaseCloneStep::class,
            'port' => 'integer',
            'test_processes' => 'integer',
            'database_server_id' => 'integer',
            'owner_instance_id' => 'integer',
            'ownership_adoption' => 'array', 'creation_receipts' => 'array', 'password' => 'encrypted',
        ];
    }
}
