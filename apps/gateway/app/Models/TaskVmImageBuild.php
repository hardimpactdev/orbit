<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The durable record of one promoted task VM base: a refresh of the host's base VM records its snapshot (docs/cli/tasks/vm-image-refresh.mmd).
 * Rows of the retired recipe image build stay readable; their `images` hold an image, which is never a usable base.
 *
 * @property int $id
 * @property int $host_node_id
 * @property string|null $recipe_identity
 * @property string|null $recipe_json
 * @property int|null $recipe_generation
 * @property array<string, array<string, string>> $images a refresh: {project, type: snapshot, vm, seed, snapshot}; a retired image build: {project, alias, fingerprint, type: virtual-machine}
 * @property Carbon|null $promoted_at
 * @property int $attempt
 * @property string|null $source_alias
 * @property string|null $source_fingerprint
 * @property bool $from_stock
 * @property string|null $builder_vm_name
 * @property string|null $image_fingerprint
 * @property TaskVmImageBuildStep|null $current_step
 * @property string|null $current_program
 * @property TaskVmImageBuildState $state
 * @property list<array{step: string, program: string|null, started_at: string|null, seconds: float|null, error: string|null, operations?: array<string, int>, phase_state?: array{observed_at?: float, boot_id?: string, attempt?: int, restart_from?: string}}> $steps
 * @property string|null $submission_pending_at
 * @property Carbon|null $cleanup_started_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Node $hostNode
 */
final class TaskVmImageBuild extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'recipe_identity', 'recipe_json', 'recipe_generation', 'images', 'promoted_at',
        'host_node_id', 'source_alias', 'source_fingerprint', 'from_stock', 'image_fingerprint',
        'current_step', 'current_program', 'state', 'steps', 'submission_pending_at', 'attempt',
    ];

    /** @var array<string, string> */
    #[\Override]
    protected $attributes = ['state' => 'running', 'from_stock' => '0', 'steps' => '[]', 'attempt' => '1'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'state' => TaskVmImageBuildState::class,
            'attempt' => 'integer',
            'recipe_generation' => 'integer',
            'images' => 'array',
            'promoted_at' => 'datetime',
            'current_step' => TaskVmImageBuildStep::class,
            'from_stock' => 'boolean',
            'steps' => 'array',
            'cleanup_started_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Node, $this> */
    public function hostNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'host_node_id');
    }
}
