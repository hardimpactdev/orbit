<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Illuminate\Support\Facades\Process;

final readonly class DnsmasqRuntimeInspector
{
    public const string ProjectionMount = '/etc/dnsmasq.d';

    public function containerId(): ?string
    {
        foreach ([
            "docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-dns'",
            'docker ps -a -q -f name=orbit-dns',
        ] as $command) {
            $result = Process::timeout(15)->run($command);

            if (! $result->successful()) {
                continue;
            }

            $containerId = strtok(string: trim($result->output()), token: PHP_EOL);

            if (is_string($containerId) && $containerId !== '') {
                return $containerId;
            }
        }

        return null;
    }

    public function projectionDirectoryIsMounted(string $expectedSource, ?string $containerId = null): bool
    {
        $containerId ??= $this->containerId();

        if ($containerId === null) {
            return false;
        }

        return new DnsmasqProjectionMountInspector()->isMounted(
            containerId: $containerId,
            expectedSource: $expectedSource,
            expectedDestination: self::ProjectionMount,
        );
    }
}
