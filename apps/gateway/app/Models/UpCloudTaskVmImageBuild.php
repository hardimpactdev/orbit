<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\TaskVms\TaskVmImageBuildState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One admitted UpCloud recipe attempt, including workers that died before recording a result.
 *
 * @property array{build_id:int,node_id:int,identity:string,generation:int,stamp:string,name:string,source:string,recipe:string,agent_sha256:string,host_key:string,upcloud:array{zone:string,plan:string,disk_gb:int},pinned_templates:list<string>,pi_artifact:array{path:string,sha256:string}|null}|null $intent
 * @property array<string, mixed> $resources
 * @property array{private:string,public:string}|null $bootstrap The build servers' host key pair; the run start copies the private key to the vault.
 * @property int $id
 * @property string $recipe_identity
 * @property TaskVmImageBuildState $state
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property array{phase: string, exit_code: int|null, stderr: string}|null $failure
 * @property string|null $template_uuid
 */
final class UpCloudTaskVmImageBuild extends Model
{
    #[\Override]
    protected $hidden = ['bootstrap'];

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
        return ['state' => TaskVmImageBuildState::class, 'started_at' => 'datetime', 'finished_at' => 'datetime', 'failure' => 'array', 'intent' => 'array', 'resources' => 'array', 'bootstrap' => 'encrypted:array'];
    }

    public function status(): string
    {
        return $this->state === TaskVmImageBuildState::Running && $this->started_at->lte(now()->subSeconds(self::RunningSeconds))
            ? 'stale-running' : $this->state->value;
    }
}
