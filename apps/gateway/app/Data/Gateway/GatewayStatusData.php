<?php

declare(strict_types=1);

namespace App\Data\Gateway;

use App\Data\Fleet\DesiredFleetStateData;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class GatewayStatusData extends Data
{
    public function __construct(
        public string $name,
        public string $status,
        public string $version,
        public string $phpVersion,
        public string $laravelVersion,
        /** What the fleet should run for this Gateway's commit, as last resolved; null until it is resolved, and for a caller that is not an active WireGuard peer. */
        public ?DesiredFleetStateData $desiredFleetState,
        public ?string $release,
        public ?string $releaseSha,
        public ?GatewayAutoReleaseStatusData $autoRelease,
        /** @var array{collected_at: ?string, last_run_ms: int|float|null, hidden_nodes: int, issues: list<array{node_id: int, node_name: string, code: string, count: int, since: ?string, newest_since: ?string, resource: array{type: string, id: ?string}|null}>}|null */
        public ?array $doctor = null,
    ) {}
}
