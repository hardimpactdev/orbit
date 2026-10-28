<?php

declare(strict_types=1);

namespace App\Data\Instances;

use App\Data\Operations\OperationData;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class InstanceSmokeRollbackReceiptData extends Data
{
    public function __construct(public string $requestKey, public bool $found, public ?OperationData $operation,
        public ?InstanceSmokeRollbackDeploymentData $deployment) {}
}
