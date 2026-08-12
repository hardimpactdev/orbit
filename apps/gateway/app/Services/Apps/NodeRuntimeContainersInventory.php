<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Apps\NodeRuntimeContainersProbeStatus;
use App\Enums\DriftKind;
use App\Models\Node;
use Throwable;

final readonly class NodeRuntimeContainersInventory
{
    private const string FAMILY = 'process';

    public function __construct(
        private RemoteAppRuntimeContainersProbe $remoteProbe,
        private NodeRuntimeContainersProbeParser $parser,
    ) {}

    public function introspect(Node $node): NodeRuntimeContainersProbe
    {
        try {
            $stdout = $this->remoteProbe->stdout($node);
        } catch (Throwable $exception) {
            return new NodeRuntimeContainersProbe(
                status: NodeRuntimeContainersProbeStatus::Error,
                containers: new ProbeSnapshot([]),
                error: $exception->getMessage(),
            );
        }

        return $this->parser->parse($stdout);
    }

    /**
     * @param  list<string>  $activeRuntimeSlugs
     * @return list<DriftEntry>
     */
    public function diff(
        Node $node,
        NodeRuntimeContainersProbe $snapshot,
        array $activeRuntimeSlugs,
    ): array {
        if ($snapshot->status === NodeRuntimeContainersProbeStatus::Error) {
            return [
                new DriftEntry(
                    family: self::FAMILY,
                    key: 'process.runtime_backend_unavailable',
                    kind: DriftKind::Unverifiable,
                    summary: "Managed process runtime inventory could not be scanned on node {$node->name}.",
                    detail: [
                        'backend' => 'docker',
                        'reason' => 'runtime_inventory_probe_failed',
                        'error' => $snapshot->error,
                    ],
                ),
            ];
        }

        if ($snapshot->status !== NodeRuntimeContainersProbeStatus::Present) {
            return [];
        }

        $drift = [];

        foreach ($snapshot->containers->keys() as $appSlug) {
            if (in_array($appSlug, $activeRuntimeSlugs, strict: true)) {
                continue;
            }

            $observed = $snapshot->containers->get($appSlug) ?? [];
            $containerName = is_string($observed['container_name'] ?? null)
                ? $observed['container_name']
                : "orbit-app-{$appSlug}";

            $drift[] = new DriftEntry(
                family: self::FAMILY,
                key: 'process.runtime_unit_extra',
                kind: DriftKind::Extra,
                summary: "Managed process runtime unit '{$containerName}' has no active app runtime intent.",
                detail: [
                    'runtime_unit' => $containerName,
                    'app' => $appSlug,
                    'reason' => 'orphaned_managed_app_runtime',
                ],
            );
        }

        return $drift;
    }
}
