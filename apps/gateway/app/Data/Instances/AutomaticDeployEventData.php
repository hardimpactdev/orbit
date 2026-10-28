<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class AutomaticDeployEventData extends Data
{
    public function __construct(
        public string $event,
        public ?int $deploymentId = null,
        public ?string $commit = null,
        public ?string $since = null,
        public ?string $result = null,
        public ?int $operationId = null,
        public ?string $errorCode = null,
        public ?string $message = null,
    ) {}
}
