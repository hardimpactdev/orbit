<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\NodeFacts\NodeFactKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The latest fact of one kind that a Node's agent observed (the Node agent observer pass).
 *
 * @property int $id
 * @property int $node_id
 * @property NodeFactKind $kind
 * @property string $digest
 * @property array<string, mixed> $data
 * @property Carbon $observed_at
 * @property Carbon $received_at
 */
final class NodeFact extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'kind' => NodeFactKind::class,
            'data' => 'array',
            'observed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
