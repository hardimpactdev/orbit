<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $node_id
 * @property string $slot
 * @property string $registry_token
 * @property string $notification_token
 * @property bool $required
 * @property ?string $boot_id
 * @property ?string $epoch
 * @property ?int $applied_revision
 * @property int $report_sequence
 * @property ?CarbonImmutable $reported_at
 * @property string $state
 * @property array<string, mixed>|null $measurements
 * @property-read Node $node
 */
final class WebSocketRuntime extends Model
{
    #[\Override]
    protected $table = 'websocket_runtimes';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    #[\Override]
    protected $guarded = [];

    #[\Override]
    protected $hidden = ['registry_token', 'notification_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['registry_token' => 'encrypted', 'notification_token' => 'encrypted', 'required' => 'boolean',
            'applied_revision' => 'integer', 'report_sequence' => 'integer', 'reported_at' => 'immutable_datetime', 'measurements' => 'array'];
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}
