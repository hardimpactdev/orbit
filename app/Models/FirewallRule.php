<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FirewallRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $node_id
 * @property string $name
 * @property string $direction
 * @property string $action
 * @property string $source
 * @property string|null $destination
 * @property int|string $port
 * @property string $protocol
 * @property string|null $reason
 * @property string $source_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Node $node
 */
class FirewallRule extends Model
{
    /** @use HasFactory<FirewallRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'node_id',
        'name',
        'direction',
        'action',
        'source',
        'destination',
        'port',
        'protocol',
        'reason',
        'source_hash',
    ];

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
