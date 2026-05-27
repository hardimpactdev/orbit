<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NodeRoleAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $node_id
 * @property string $role
 * @property string $status
 * @property array<string, mixed>|null $settings
 * @property string|null $last_error
 * @property Carbon|null $converged_at
 * @property-read Node|null $node
 */
class NodeRoleAssignment extends Model
{
    /** @use HasFactory<NodeRoleAssignmentFactory> */
    use HasFactory;

    #[\Override]
    protected $table = 'node_roles';

    #[\Override]
    protected $fillable = [
        'node_id',
        'role',
        'status',
        'settings',
        'last_error',
        'converged_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'converged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
