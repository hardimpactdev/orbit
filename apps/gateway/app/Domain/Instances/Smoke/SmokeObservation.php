<?php

declare(strict_types=1);

namespace App\Domain\Instances\Smoke;

use App\Data\Instances\InstanceSmokeResultData;

final readonly class SmokeObservation
{
    public function __construct(public InstanceSmokeResultData $result, public ?string $errorCode = null) {}
}
