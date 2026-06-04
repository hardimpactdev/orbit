<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class ToolRuntimePlatformResolver
{
    public function fromNodePlatform(string $platform): ToolRuntimePlatform
    {
        $platform = trim(strtolower($platform));

        return new ToolRuntimePlatform(
            nodePlatform: $platform,
            platformFamily: $this->platformFamily($platform),
        );
    }

    private function platformFamily(string $platform): string
    {
        if ($platform === 'ubuntu' || str_starts_with($platform, 'ubuntu_')) {
            return 'ubuntu';
        }

        if ($platform === 'linux' || str_starts_with($platform, 'linux_') || str_starts_with($platform, 'linux-')) {
            return 'linux';
        }

        if ($platform === 'macos' || str_starts_with($platform, 'macos_') || str_starts_with($platform, 'macos-')) {
            return 'macos';
        }

        return $platform;
    }
}
