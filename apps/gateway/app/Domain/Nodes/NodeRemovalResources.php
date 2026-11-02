<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

/** Fixed Orbit-owned paths. Packages, accounts, releases and application data are retained. */
final readonly class NodeRemovalResources
{
    /** @param array<string,mixed> $ssh
     * @return array{files:list<array<string,mixed>>,directories:list<array<string,mixed>>}
     */
    public static function footprints(array $ssh, bool $observer = false): array
    {
        $files = ['/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf',
            '/etc/systemd/system/orbit-cadvisor.service', '/etc/systemd/system/orbit-fpm-exporter.service',
            '/usr/local/bin/orbit-cadvisor', '/usr/local/bin/orbit-fpm-exporter'];
        $directories = ['/etc/orbit/service-metrics'];
        if ($observer) {
            $files = [...$files, '/etc/systemd/system/orbit-agent.service', '/usr/local/bin/orbit-agent'];
            $directories[] = '/etc/orbit/agent';
        }
        $rows = static fn (array $paths): array => array_values(array_map(static fn (string $path): array => ['ssh' => $ssh, 'path' => $path], $paths));

        return ['files' => $rows($files), 'directories' => $rows($directories)];
    }
}
