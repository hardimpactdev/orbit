<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Services\Vpn\VpnDnsSwarmManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class DnsmasqRuntimeManager
{
    public function __construct(
        private readonly string $rootPath,
        private readonly ?VpnDnsSwarmManager $swarmManager = null,
        private readonly DnsmasqRuntimeInspector $runtimeInspector = new DnsmasqRuntimeInspector,
    ) {}

    public function projectionDirectoryIsMounted(): bool
    {
        return $this->runtimeInspector->projectionDirectoryIsMounted($this->rootPath.'/dnsmasq.d');
    }

    public function activate(): void
    {
        if ($this->swarmManager()->restartDnsServiceIfPresent()) {
            return;
        }

        $container = Process::timeout(10)->run('docker container inspect orbit-dns');

        if (! $container->successful()) {
            return;
        }

        $restart = Process::timeout(30)->run('docker restart orbit-dns');

        if ($restart->successful()) {
            return;
        }

        throw new RuntimeException(
            'Failed to restart orbit-dns: '.trim($restart->errorOutput().' '.$restart->output()),
        );
    }

    public function guardOwnerReconciliation(): void
    {
        if (! $this->hasLegacyMonolith()) {
            return;
        }

        throw new RuntimeException(
            'Refusing owner-scoped reconciliation while legacy monolithic dnsmasq.conf remains; run the DNS installer migration.',
        );
    }

    public function guardLegacyMigration(): void
    {
        if ($this->projectionDirectoryIsMounted()) {
            return;
        }

        throw new RuntimeException(
            'Refusing to migrate legacy monolithic dnsmasq.conf before the live dnsmasq.d runtime mount is proven.',
        );
    }

    private function hasLegacyMonolith(): bool
    {
        $basePath = $this->rootPath.'/'.DnsmasqLayoutReconciler::BaseConfig;

        return File::exists($basePath) && str_contains(File::get($basePath), 'address=/');
    }

    private function swarmManager(): VpnDnsSwarmManager
    {
        return $this->swarmManager ?? app(VpnDnsSwarmManager::class);
    }
}
