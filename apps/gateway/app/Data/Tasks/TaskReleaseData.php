<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Models\Task;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** One task copy group in the `task:status` view that waits for a person's release or whose automatic publish was refused. */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskReleaseData extends Data
{
    public function __construct(
        public int $id,
        public int $projectId,
        public string $project,
        public string $projectCode,
        public string $title,
        public string $state,
        public ?int $checkId,
        public ?string $commit,
        public ?string $errorCode,
    ) {}

    public static function fromPublication(Task $group, TaskPublicationData $publication): self
    {
        return new self($group->id, $group->project_id, $group->project->slug, $group->project->code, $group->title,
            $publication->state, $publication->checkId, $publication->commit, $publication->errorCode);
    }
}
