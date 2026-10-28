<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class InstanceSmokeRepairData extends Data
{
    public function __construct(public string $requestKey, public int $instanceId, public int $projectId, public string $failureKey,
        public int $groupId, public int $taskId) {}
}
