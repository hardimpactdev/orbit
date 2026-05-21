<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<string> $permissions
 * @property list<string> $custom_permissions
 */
class NodeAccess extends Model
{
    protected $table = 'node_access';

    protected $attributes = [
        'permissions' => '["*"]',
        'custom_permissions' => '[]',
    ];

    protected $fillable = [
        'consumer_node_id',
        'serving_node_id',
        'permissions',
        'custom_permissions',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'custom_permissions' => 'array',
        ];
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'consumer_node_id');
    }

    public function serving(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'serving_node_id');
    }
}
