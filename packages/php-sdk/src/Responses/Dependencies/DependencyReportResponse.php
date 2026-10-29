<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

use Orbit\Sdk\Support\DependencyResponseValue;

final readonly class DependencyReportResponse
{
    /** @param list<DependencyPackageResponse> $packages
     * @param list<DependencyFindingResponse> $findings */
    public function __construct(
        public string $sourceCommit,
        public string $publicationId,
        public string $path,
        public string $ecosystem,
        public string $packageManager,
        public string $observedAt,
        public string $runId,
        public string $basis,
        public string $observedOn,
        public ?int $instanceId,
        public string $freshness,
        public array $packages,
        public int $packageCount,
        public int $packagesPage,
        public array $findings,
        public int $findingCount,
        public int $findingsPage,
    ) {}

    public static function fromArray(#[\SensitiveParameter] mixed $data): self
    {
        $v = DependencyResponseValue::fields($data, ['source_commit', 'publication_id', 'path', 'ecosystem', 'package_manager', 'observed_at', 'run_id', 'basis', 'observed_on', 'instance_id', 'freshness', 'packages', 'package_count', 'packages_page', 'findings', 'finding_count', 'findings_page']);

        return new self(
            DependencyResponseValue::text($v['source_commit']),
            DependencyResponseValue::text($v['publication_id']),
            DependencyResponseValue::text($v['path']),
            DependencyResponseValue::text($v['ecosystem']),
            DependencyResponseValue::text($v['package_manager']),
            DependencyResponseValue::text($v['observed_at']),
            DependencyResponseValue::text($v['run_id']),
            DependencyResponseValue::text($v['basis']),
            DependencyResponseValue::text($v['observed_on']),
            $v['instance_id'] === null ? null : DependencyResponseValue::integer($v['instance_id']),
            DependencyResponseValue::text($v['freshness']),
            array_map(DependencyPackageResponse::fromArray(...), DependencyResponseValue::rows($v['packages'], 100)),
            DependencyResponseValue::integer($v['package_count']),
            DependencyResponseValue::integer($v['packages_page']),
            array_map(DependencyFindingResponse::fromArray(...), DependencyResponseValue::rows($v['findings'], 100)),
            DependencyResponseValue::integer($v['finding_count']),
            DependencyResponseValue::integer($v['findings_page']),

        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_commit' => $this->sourceCommit,
            'publication_id' => $this->publicationId,
            'path' => $this->path,
            'ecosystem' => $this->ecosystem,
            'package_manager' => $this->packageManager,
            'observed_at' => $this->observedAt,
            'run_id' => $this->runId,
            'basis' => $this->basis,
            'observed_on' => $this->observedOn,
            'instance_id' => $this->instanceId,
            'freshness' => $this->freshness,
            'packages' => array_map(static fn (DependencyPackageResponse $item): array => $item->toArray(), $this->packages),
            'package_count' => $this->packageCount,
            'packages_page' => $this->packagesPage,
            'findings' => array_map(static fn (DependencyFindingResponse $item): array => $item->toArray(), $this->findings),
            'finding_count' => $this->findingCount,
            'findings_page' => $this->findingsPage,
        ];
    }
}
