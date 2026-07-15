<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Node;
use RuntimeException;

final class CliArtifactPlatform
{
    public static function forNode(Node $node): string
    {
        $platform = strtolower(trim((string) $node->platform));
        $architecture = strtolower(trim((string) $node->architecture));

        if (str_contains($platform, 'macos') || str_contains($platform, 'darwin')) {
            return 'darwin-arm64';
        }

        if (in_array($architecture, ['arm64', 'aarch64'], true)) {
            return 'linux-arm64';
        }

        if (in_array($architecture, ['amd64', 'x86_64', 'x64'], true)) {
            return 'linux-amd64';
        }

        if (str_contains($platform, 'arm64') || str_contains($platform, 'aarch64')) {
            return 'linux-arm64';
        }

        if (
            $platform === ''
            || str_contains($platform, 'linux')
            || str_contains($platform, 'ubuntu')
            || str_contains($platform, 'debian')
            || str_contains($platform, 'amd64')
            || str_contains($platform, 'x86_64')
            || str_contains($platform, 'x64')
        ) {
            return 'linux-amd64';
        }

        throw new RuntimeException(
            "Unsupported workload update platform [{$node->platform}] for node [{$node->name}].",
        );
    }
}
