<?php

declare(strict_types=1);

namespace App\Data\Instances;

use App\Models\InstanceNodeServiceTarget;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class InstanceNodeServiceTargetRecordData extends Data
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
    ) {}

    public static function fromModel(InstanceNodeServiceTarget $target): self
    {
        return new self($target->id, $target->instance_id, $target->node_id, $target->process_id,
            $target->protocol, $target->port, $target->host_env_key, $target->port_env_key);
    }
}
