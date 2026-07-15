<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $node_id
 * @property int $initiating_node_id
 * @property array<string, mixed> $request
 * @property string $status
 * @property array<string, mixed>|null $last_error
 * @property-read Node $node
 * @property-read Node $initiatingNode
 */
final class NodeBootstrap extends Model
{
    use HasUuids;

    /** @var array<int, string> */
    #[\Override]
    protected $fillable = [
        'node_id',
        'initiating_node_id',
        'request',
        'status',
        'last_error',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'request' => 'array',
            'last_error' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function initiatingNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'initiating_node_id');
    }
}
