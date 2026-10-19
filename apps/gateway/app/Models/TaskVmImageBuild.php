<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * The durable record of one base image build. Steps stay in execution order; a program step carries its recipe name.
 *
 * @property int $id
 * @property int $host_node_id
 * @property string|null $recipe_identity
 * @property string|null $recipe_json
 * @property int|null $recipe_generation
 * @property array<string, array{project: string, alias: string, fingerprint: string, type: string}> $images
 * @property Carbon|null $promoted_at
 * @property array<string, mixed>|null $fleet_convergence
 * @property int $attempt
 * @property string|null $source_alias
 * @property string|null $source_fingerprint
 * @property bool $from_stock
 * @property string|null $builder_vm_name
 * @property string|null $image_fingerprint
 * @property TaskVmImageBuildStep|null $current_step
 * @property string|null $current_program
 * @property TaskVmImageBuildState $state
 * @property list<array{step: string, program: string|null, started_at: string|null, seconds: float|null, error: string|null, phase_state?: array{observed_at?: float, boot_id?: string, attempt?: int, restart_from?: string}}> $steps
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
            'fleet_convergence' => 'array',
            'images' => 'array',
            'promoted_at' => 'datetime',
            'current_step' => TaskVmImageBuildStep::class,
            'from_stock' => 'boolean',
            'steps' => 'array',
            'cleanup_started_at' => 'datetime',
        ];
    }

    /**
     * Let the database arbitrate concurrent starts and resumes. Assign the builder name in the same transaction
     * as the insert, once SQLite has assigned the build id.
     *
     * @param  array{touch?: bool|null}  $options
     */
    #[\Override]
    public function save(array $options = []): bool
    {
        try {
            return $this->getConnection()->transaction(function () use ($options): bool {
                $creating = ! $this->exists;
                $saved = parent::save($options);
                if ($saved && $creating) {
                    $this->builder_vm_name = 'tvm-image-'.$this->id;
                    parent::save($options);
                }

                return $saved;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'task_vm_image_builds.host_node_id')) {
                throw $exception;
            }

            throw new TaskVmException(TaskVmError::ImageBuildRunning, 'A task VM image build is already running on this host.', previous: $exception);
        }
    }

    /** @return BelongsTo<Node, $this> */
    public function hostNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'host_node_id');
    }
}
