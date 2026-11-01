<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GatewayReleases\GatewayReleaseSwampLoad;
use App\Models\GatewayRelease;
use Closure;

/** The native publication boundary for release pipeline tests. */
final class FakeGatewayReleaseSwampLoad implements GatewayReleaseSwampLoad
{
    /** @var list<array{sha:string,record:?int}> */
    public array $calls = [];

    public ?Closure $during = null;

    public function load(string $sha, ?GatewayRelease $record): array
    {
        $this->calls[] = ['sha' => $sha, 'record' => $record?->id];
        if ($this->during !== null) {
            ($this->during)($sha, $record);
        }

        return ['outcome' => 'verified', 'sha' => $sha];
    }
}
