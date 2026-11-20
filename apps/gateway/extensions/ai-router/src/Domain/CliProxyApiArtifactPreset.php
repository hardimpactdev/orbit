<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Processes\Artifacts\ProcessArtifactPreset;

/** The router's upstream release, applied with `process:artifact:set PROCESS --preset=cliproxyapi`. */
final readonly class CliProxyApiArtifactPreset implements ProcessArtifactPreset
{
    public function name(): string
    {
        return 'cliproxyapi';
    }

    public function fields(): array
    {
        return [
            'repository' => 'router-for-me/CLIProxyAPI',
            'asset' => 'CLIProxyAPI_{version}_linux_amd64.tar.gz',
            'binary' => 'cli-proxy-api',
            'checksums' => 'checksums.txt',
            'version_args' => ['--help'],
        ];
    }
}
