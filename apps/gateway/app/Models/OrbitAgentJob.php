<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $type
 * @property string $status
 * @property int $target_node_id
 * @property string|null $operation_run_id
 * @property array<string, mixed> $payload
 * @property Carbon|null $claimed_at
 * @property Carbon|null $finished_at
 * @property-read Node $targetNode
 * @property-read OperationRun|null $operationRun
 */
class OrbitAgentJob extends Model
{
    use HasUuids;

    #[\Override]
    protected $fillable = [
        'id',
        'type',
        'status',
        'target_node_id',
        'operation_run_id',
        'payload',
        'claimed_at',
        'finished_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'claimed_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'target_node_id');
    }

    /**
     * @return BelongsTo<OperationRun, $this>
     */
    public function operationRun(): BelongsTo
    {
        return $this->belongsTo(OperationRun::class);
    }
}
