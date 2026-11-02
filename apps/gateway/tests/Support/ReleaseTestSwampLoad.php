<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseSwampLoad;

/** Pipeline fixtures have no Swamp serve. It records each loaded commit and can refuse one. */
final class ReleaseTestSwampLoad implements GatewayReleaseSwampLoad
{
    /** @var list<string> */
    public array $loaded = [];

    public function __construct(private readonly bool $fails = false) {}

    public function load(string $sha): array
    {
        if ($this->fails) {
            throw new GatewayReleaseException('swamp', 'gateway.release_swamp_load_failed', "Swamp could not load {$sha}.", sha: $sha);
        }
        $this->loaded[] = $sha;

        return ['outcome' => 'loaded', 'operation_id' => count($this->loaded)];
    }
}
