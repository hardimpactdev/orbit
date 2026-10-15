<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\TaskVms\TaskVmImageBuildState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One admitted UpCloud recipe attempt, including workers that died before recording a result.
 *
 * @property int $id
 * @property string $recipe_identity
 * @property TaskVmImageBuildState $state
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string|null $template_uuid
 */
final class UpCloudTaskVmImageBuild extends Model
{
    /** Outlasts the queued job timeout and matches the builder's running mark and sweep window. */
    public const int RunningSeconds = 2 * 3600;

    #[\Override]
    protected $table = 'upcloud_task_vm_image_builds';

    #[\Override]
    public $timestamps = false;

    /** @var list<string> */
    #[\Override]
    protected $fillable = ['recipe_identity', 'state', 'started_at', 'finished_at', 'template_uuid'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['state' => TaskVmImageBuildState::class, 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function status(): string
    {
        return $this->state === TaskVmImageBuildState::Running && $this->started_at->lte(now()->subSeconds(self::RunningSeconds))
            ? 'stale-running' : $this->state->value;
    }

    public function fenced(): bool
    {
        return $this->state === TaskVmImageBuildState::Failed || $this->status() === 'stale-running';
    }

    public function fenceMessage(): string
    {
        return "UpCloud recipe [{$this->recipe_identity}] is fenced: attempt [{$this->id}] is {$this->status()} and no verified template exists. Retry with php artisan task-vms:build-recipe --retry (also retries failed Incus builds).";
    }
}
