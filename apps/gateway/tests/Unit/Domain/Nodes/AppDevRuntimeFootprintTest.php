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

it('recreates the volatile role folder with its required metadata at boot', function (): void {
    $rules = explode("\n", TmpfilesFootprintArtifact::Rule);
    foreach (AppDevRuntimeFootprint::directories() as $directory) {
        if (str_starts_with($directory['path'], '/dev/shm/')) {
            expect($rules)->toContain(sprintf('d %s %s %s %s -', $directory['path'], $directory['mode'], $directory['owner'], $directory['group']));
        }
    }
});
