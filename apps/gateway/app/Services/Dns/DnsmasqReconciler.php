<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Services\Vpn\VpnDnsSwarmManager;

class DnsmasqReconciler extends DnsmasqLayoutReconciler
{
    public function __construct(
        DnsmasqBaseConfigBuilder $baseConfigBuilder = new DnsmasqBaseConfigBuilder,
        NodeDnsmasqRecordsBuilder $nodeRecordsBuilder = new NodeDnsmasqRecordsBuilder,
        ProxyDnsmasqRecordsBuilder $proxyRecordsBuilder = new ProxyDnsmasqRecordsBuilder,
        string $rootPath = '',
        ?VpnDnsSwarmManager $swarmManager = null,
    ) {
        $projectionSetBuilder = new DnsmasqProjectionSetBuilder(
            baseConfigBuilder: $baseConfigBuilder,
            nodeRecordsBuilder: $nodeRecordsBuilder,
            proxyRecordsBuilder: $proxyRecordsBuilder,
            rootPath: $rootPath,
        );
        $runtimeManager = new DnsmasqRuntimeManager(rootPath: $rootPath, swarmManager: $swarmManager);

        parent::__construct(
            projectionSetBuilder: $projectionSetBuilder,
            projectionWriter: new DnsmasqProjectionWriter(rootPath: $rootPath, runtimeManager: $runtimeManager),
            runtimeManager: $runtimeManager,
        );
    }

    public function reconcileBase(): bool
    {
        $this->runtimeManager->guardOwnerReconciliation();

        return $this->projectionWriter->publishAndActivate(fn (): array => $this->projectionSetBuilder->base());
    }

    public function reconcileNodeRecords(): bool
    {
        $this->runtimeManager->guardOwnerReconciliation();

        return $this->projectionWriter->publishAndActivate(fn (): array => $this->projectionSetBuilder->nodeRecords());
    }

    public function reconcileProxyRecords(): bool
    {
        $this->runtimeManager->guardOwnerReconciliation();

        return $this->projectionWriter->publishAndActivate(fn (): array => $this->projectionSetBuilder->proxyRecords());
    }

    public function reconcileRecords(): bool
    {
        $this->runtimeManager->guardOwnerReconciliation();

        return $this->projectionWriter->publishAndActivate($this->gatewayRecordProjections(...));
    }
}
