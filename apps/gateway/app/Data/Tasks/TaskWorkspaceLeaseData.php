<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskWorkspaceLeaseData extends Data
{
    /** @param list<TaskWorkspaceActorData> $waiters */
    public function __construct(
        public int $instanceId,
        public string $instanceName,
        public int $groupId,
        public string $projectCode,
        public ?TaskWorkspaceActorData $holder,
        public array $waiters,
    ) {}
}
