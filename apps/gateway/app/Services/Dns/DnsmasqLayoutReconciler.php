<?php

declare(strict_types=1);

namespace App\Services\Dns;

class DnsmasqLayoutReconciler
{
    public const string BaseConfig = 'dnsmasq.conf';

    public const string NodeRecords = 'dnsmasq.d/10-node-records.conf';

    public const string ProxyRecords = 'dnsmasq.d/20-proxy-records.conf';

    public function __construct(
        protected readonly DnsmasqProjectionSetBuilder $projectionSetBuilder,
        protected readonly DnsmasqProjectionWriter $projectionWriter,
        protected readonly DnsmasqRuntimeManager $runtimeManager,
    ) {}

    public function stageAllForInstall(): bool
    {
        return $this->projectionWriter->stage($this->gatewayProjections(...));
    }

    public function stageLegacyMigrationLayout(): bool
    {
        return $this->projectionWriter->stage(fn (): array => $this->projectionSetBuilder->legacyPlaceholders());
    }

    public function migrateLegacyLayout(): bool
    {
        $this->runtimeManager->guardLegacyMigration();

        return $this->projectionWriter->publishAndActivate($this->legacyMigrationProjections(...));
    }

    public function stageRuntimeLayout(): bool
    {
        return $this->projectionWriter->stage(fn (): array => $this->projectionSetBuilder->runtimeLayout());
    }

    public function projectionDirectoryIsMounted(): bool
    {
        return $this->runtimeManager->projectionDirectoryIsMounted();
    }

    public function activateStagedConfiguration(): void
    {
        $this->runtimeManager->activate();
    }

    /** @return array<string, string> */
    protected function gatewayProjections(): array
    {
        return [
            ...$this->projectionSetBuilder->base(),
            ...$this->gatewayRecordProjections(),
        ];
    }

    /** @return array<string, string> */
    protected function gatewayRecordProjections(): array
    {
        return $this->projectionSetBuilder->records();
    }

    /** @return array<string, string> */
    protected function legacyMigrationProjections(): array
    {
        return [
            ...$this->gatewayRecordProjections(),
            ...$this->projectionSetBuilder->base(),
        ];
    }
}
