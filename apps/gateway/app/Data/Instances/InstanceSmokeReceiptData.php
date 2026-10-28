<?php

declare(strict_types=1);

namespace App\Data\Instances;

use App\Data\Operations\OperationData;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class InstanceSmokeReceiptData extends Data
{
    public function __construct(public string $requestKey, public bool $found, public ?OperationData $operation, public ?InstanceSmokeResultData $smoke) {}
}
