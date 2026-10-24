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
        public string $thread,
        public ?string $model = null,
        public ?int $check = null,
        /** @var array{operation: int, plan_sha256: string}|null */
        public ?array $analysis = null,
    ) {}

    public function threadRole(): TaskThreadRole
    {
        return $this->role === 'implementer' ? TaskThreadRole::Implementer : TaskThreadRole::Reviewer;
    }
}
