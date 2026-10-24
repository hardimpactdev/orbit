<?php

declare(strict_types=1);

use App\Domain\Fleet\CliReleaseName;
use App\Infrastructure\Fleet\StaticCliRelease;

it('names a CLI release from a local manifest only for a disposable topology', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'cli-release');
    file_put_contents($path, json_encode([
        'number' => 9001,
        'checksums_url' => 'https://gateway.orbit/fleet-proof/SHA256SUMS',
        'assets' => [['platform' => 'linux-x86_64', 'url' => 'https://gateway.orbit/fleet-proof/orbit', 'sha256' => str_repeat('a', 64)]],
    ], JSON_THROW_ON_ERROR));
    $release = new StaticCliRelease($path);
    $commit = str_repeat('b', 40);

    $found = $release->find($commit, new CliReleaseName((int) $release->count($commit)));
    unlink($path);

    expect($release->commit($commit))->toBe($commit)
        ->and($found->version)->toBe('0.9001.0')
        ->and($found->assets[0]->name)->toBe('orbit-0.9001.0-linux-x86_64')
        ->and(new StaticCliRelease('/missing')->count($commit))->toBeNull();
});
