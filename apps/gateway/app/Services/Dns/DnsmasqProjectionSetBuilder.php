<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Illuminate\Support\Facades\File;

class DnsmasqProjectionSetBuilder
{
    public function __construct(
        private readonly DnsmasqBaseConfigBuilder $baseConfigBuilder,
        private readonly NodeDnsmasqRecordsBuilder $nodeRecordsBuilder,
        private readonly ProxyDnsmasqRecordsBuilder $proxyRecordsBuilder,
        private readonly string $rootPath,
    ) {}

    /** @return array<string, string> */
    public function base(): array
    {
        return [DnsmasqLayoutReconciler::BaseConfig => $this->baseConfigBuilder->build()];
    }

    /** @return array<string, string> */
    public function nodeRecords(): array
    {
        return [DnsmasqLayoutReconciler::NodeRecords => $this->nodeRecordsBuilder->buildGatewayState()];
    }

    /** @return array<string, string> */
    public function proxyRecords(): array
    {
        return [DnsmasqLayoutReconciler::ProxyRecords => $this->proxyRecordsBuilder->buildGatewayState()];
    }

    /** @return array<string, string> */
    public function records(): array
    {
        return [
            ...$this->nodeRecords(),
            ...$this->proxyRecords(),
        ];
    }

    /** @return array<string, string> */
    public function legacyPlaceholders(): array
    {
        return [
            DnsmasqLayoutReconciler::NodeRecords => "# orbit-managed=node-dns-records\n",
            DnsmasqLayoutReconciler::ProxyRecords => "# orbit-managed=proxy-dns-records\n",
        ];
    }

    /** @return array<string, string> */
    public function runtimeLayout(): array
    {
        $projections = $this->base();

        foreach ($this->legacyPlaceholders() as $relativePath => $placeholder) {
            if (File::exists($this->rootPath.'/'.$relativePath)) {
                continue;
            }

            $projections[$relativePath] = $placeholder;
        }

        return $projections;
    }
}
