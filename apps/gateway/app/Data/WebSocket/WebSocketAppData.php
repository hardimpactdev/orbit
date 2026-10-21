<?php

declare(strict_types=1);

namespace App\Data\WebSocket;

use App\Models\WebSocketApp;
use App\Models\WebSocketAppTarget;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** A Reverb app without its secret. */
#[MapOutputName(SnakeCaseMapper::class)]
final class WebSocketAppData extends Data
{
    /**
     * @param  list<int>  $instanceIds
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $appId,
        public string $key,
        public array $instanceIds,
    ) {}

    public static function fromModel(WebSocketApp $app): self
    {
        return new self(
            id: $app->id,
            name: $app->name,
            appId: $app->app_id,
            key: $app->key,
            instanceIds: array_values($app->targets()
                ->orderBy('instance_id')
                ->get()
                ->map(static fn (WebSocketAppTarget $target): int => $target->instance_id)
                ->all()),
        );
    }
}
