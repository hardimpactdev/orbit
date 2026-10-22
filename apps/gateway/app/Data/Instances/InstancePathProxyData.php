<?php

declare(strict_types=1);

namespace App\Data\Instances;

use App\Models\InstancePathProxy;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class InstancePathProxyData extends Data
{
    public function __construct(
        public int $instanceId,
        public int $routeId,
        public int $processId,
        public int $port,
        public string $prefix,
        public string $endpoint,
        public bool $enabled,
    ) {}

    public static function fromModel(InstancePathProxy $proxy): self
    {
        return new self($proxy->instance_id, $proxy->route_id, $proxy->process_id, $proxy->port,
            $proxy->prefix, 'https://'.$proxy->route->domain.$proxy->prefix, $proxy->enabled);
    }
}
