<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskThreadRole;
use Spatie\LaravelData\Data;

final class TaskTurnData extends Data
{
    public function __construct(
        public int $subtask,
        public string $role,
        public string $prompt,
        /** `new`, a thread id, or null: a task copy then continues the role's thread, and a task VM starts a new one. */
        public ?string $thread = null,
        public ?string $model = null,
        public ?int $check = null,
    ) {}

    public function threadRole(): TaskThreadRole
    {
        return $this->role === 'implementer' ? TaskThreadRole::Implementer : TaskThreadRole::Reviewer;
    }
}
