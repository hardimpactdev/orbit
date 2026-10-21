<?php

declare(strict_types=1);

namespace App\Data\WebSocket;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** Names the keys an attachment wrote or removed. It never carries their values. */
#[MapOutputName(SnakeCaseMapper::class)]
final class WebSocketAppAttachmentData extends Data
{
    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public int $instanceId,
        public string $name,
        public array $keys,
        public string $operation,
        public bool $changed,
        public int $keyCount,
    ) {}
}
