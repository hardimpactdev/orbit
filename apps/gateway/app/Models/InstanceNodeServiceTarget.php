<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $instance_id
 * @property int $process_id
 * @property int $node_id
 * @property string $protocol
 * @property int $port
 * @property string $host_env_key
 * @property string $port_env_key
 * @property-read Instance $instance
 * @property-read Node $node
 * @property-read Process $process
 */
final class InstanceNodeServiceTarget extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['instance_id', 'process_id', 'node_id', 'protocol', 'port', 'host_env_key', 'port_env_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['port' => 'integer'];
    }

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
