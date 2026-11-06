<?php

declare(strict_types=1);

use App\Domain\Nodes\AppDevRuntimeFootprint;
use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;

it('names the hibernation folders a served development site needs, owned for Caddy', function (): void {
    expect(AppDevRuntimeFootprint::directories())->toBe([
        ['path' => '/dev/shm/orbit/hibernation', 'owner' => 'root', 'group' => 'caddy', 'mode' => '0755'],
        ['path' => '/data/caddy/orbit/hibernation', 'owner' => 'root', 'group' => 'caddy', 'mode' => '2775'],
    ])->and(array_column(AppDevRuntimeFootprint::files(), 'content', 'path'))->toBe([TmpfilesFootprintArtifact::Path => TmpfilesFootprintArtifact::Rule]);
});
