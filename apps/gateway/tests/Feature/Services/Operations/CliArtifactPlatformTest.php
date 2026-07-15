<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Operations\CliArtifactPlatform;

it('selects the observed Linux architecture for workload artifacts', function (
    string $architecture,
    string $artifact,
): void {
    $node = Node::factory()->make([
        'platform' => 'ubuntu_24-04',
        'architecture' => $architecture,
    ]);

    expect(CliArtifactPlatform::forNode($node))->toBe($artifact);
})->with([
    'amd64' => ['amd64', 'linux-amd64'],
    'arm64' => ['arm64', 'linux-arm64'],
]);

it('preserves the Darwin artifact for existing macOS platform records', function (): void {
    $node = Node::factory()->make([
        'platform' => 'macos_15-4',
        'architecture' => 'amd64',
    ]);

    expect(CliArtifactPlatform::forNode($node))->toBe('darwin-arm64');
});
