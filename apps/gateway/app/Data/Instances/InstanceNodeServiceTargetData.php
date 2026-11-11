<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class InstanceNodeServiceTargetData extends Data
{
    public function __construct(
        public int $id,
        public int $instanceId,
        public int $nodeId,
        public int $processId,
        public string $protocol,
        public int $port,
        public string $hostEnvKey,
        public string $portEnvKey,
        public bool $changed,
        public ?string $reasonCode = null,
    ) {}
}
