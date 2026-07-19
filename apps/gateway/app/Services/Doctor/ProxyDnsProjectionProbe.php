<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Dns\ProxyDnsmasqRecordsBuilder;
use Illuminate\Support\Facades\File;

final readonly class ProxyDnsProjectionProbe
{
    public function __construct(
        private ProxyDnsmasqRecordsBuilder $recordsBuilder,
        private string $rootPath,
    ) {}

    /** @return list<DriftEntry> */
    public function drift(Node $router): array
    {
        $path = $this->path();

        if (! $this->dnsCapabilityRequired()) {
            return [];
        }

        $expected = $this->recordsBuilder->buildGatewayState();
        $actual = File::exists($path) ? File::get($path) : null;

        if ($actual === $expected) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'proxy',
                key: 'proxy.dns_mapping_mismatch',
                kind: $actual === null ? DriftKind::Missing : DriftKind::Divergent,
                summary: "Proxy-owned private service DNS projection on {$router->name} is missing or mismatched.",
                detail: [
                    'path' => $path,
                ],
            ),
        ];
    }

    private function path(): string
    {
        return $this->rootPath.'/'.DnsmasqReconciler::ProxyRecords;
    }

    private function dnsCapabilityRequired(): bool
    {
        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', static fn ($query) => $query
                ->where('role', NodeRoleName::Vpn->value)
                ->where('status', NodeRoleStatus::Active->value))
            ->exists();
    }
}
