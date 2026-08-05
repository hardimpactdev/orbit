<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProcessEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property ProcessEventType $event
 * @property string $event_id
 * @property int|null $process_id
 * @property string $process_name
 * @property int|null $app_id
 * @property int|null $instance_id
 * @property int|null $workspace_id
 * @property int|null $node_id
 * @property string|null $unit_name
 * @property int|null $exit_code
 * @property string|null $exit_status
 * @property Carbon|null $exited_at
 * @property Carbon|null $recorded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Process|null $process
 * @property-read App|null $app
 * @property-read App|null $app
 * @property-read Instance|null $instance
 * @property-read Node|null $node
 * @property-read Workspace|null $workspace
 */
class ProcessEvent extends Model
{
    use HasFactory;

    #[Override]
    protected $fillable = [
        'event',
        'event_id',
        'process_id',
        'process_name',
        'app_id',
        'instance_id',
        'workspace_id',
        'node_id',
        'unit_name',
        'exit_code',
        'exit_status',
        'exited_at',
        'recorded_at',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'event' => ProcessEventType::class,
            'exit_code' => 'integer',
            'exited_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    /**
     * @return BelongsTo<Instance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return BelongsTo<Process, $this>
     */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
