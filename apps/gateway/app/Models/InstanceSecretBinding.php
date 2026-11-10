<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A secret binding (docs/reference/development-containers.md#secret-bindings): it marks one stored environment key of a container
 * Instance. The key's stored value stays its only value; this row says where the Node's egress proxy may inject it.
 *
 * @property int $id
 * @property int $instance_id
 * @property string $env_key
 * @property list<string> $hosts
 * @property list<string> $headers
 * @property string $placeholder
 * @property-read Instance $instance
 */
final class InstanceSecretBinding extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['instance_id', 'env_key', 'hosts', 'headers', 'placeholder'];

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['hosts' => 'array', 'headers' => 'array'];
    }
}
