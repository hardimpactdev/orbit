<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $node_id
 * @property string $domain
 * @property int|null $app_id
 * @property string $owner_type
 * @property string $kind
 * @property string $source_hash
 * @property array<string, mixed>|null $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Node $node
 * @property-read App|null $app
 */
class ProxyRoute extends Model
{
    protected $fillable = [
        'node_id',
        'domain',
        'app_id',
        'owner_type',
        'kind',
        'source_hash',
        'config',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'config' => 'array',
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
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
