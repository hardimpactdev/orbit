<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\ExporterSelectionReason;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsExporterProjectionItem;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\TaskVm;

final readonly class NativeMetricsExporterProjection implements MetricsExporterProjection
{
    public function __construct(
        private ExporterSelector $selector,
        private ExporterPreferenceRepository $preferences,
        private ManagedNodeEligibility $eligibility = new ManagedNodeEligibility,
    ) {}

    public function for(Node $metricsNode): array
    {
        $items = [];
        $candidate = app(RouteRenderContext::class)->enrollment();

        foreach (Node::query()
            ->with('roles')
            ->where(static function ($query) use ($candidate): void {
                $query->where('status', LifecycleStatus::Active);
                if ($candidate !== null && $candidate->platform === 'linux') {
                    $query->orWhere(static fn ($query) => $query->whereKey($candidate->nodeId)
                        ->whereIn('status', [LifecycleStatus::Provisioning, LifecycleStatus::Failed]));
                }
            })
            ->orderBy('id')
            ->get() as $node) {
            $item = $this->item($metricsNode, $node);

            if ($item instanceof MetricsExporterProjectionItem) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function forNode(Node $metricsNode, Node $node): ?MetricsExporterProjectionItem
    {
        if (! $node->exists) {
            return null;
        }

        $candidate = app(RouteRenderContext::class)->enrollment();
        $projectedNode = Node::query()
            ->with('roles')
            ->whereKey($node->getKey())
            ->where(static function ($query) use ($candidate): void {
                $query->where('status', LifecycleStatus::Active);
                if ($candidate !== null && $candidate->platform === 'linux') {
                    $query->orWhere(static fn ($query) => $query->whereKey($candidate->nodeId)
                        ->whereIn('status', [LifecycleStatus::Provisioning, LifecycleStatus::Failed]));
                }
            })
            ->first();

        if (! $projectedNode instanceof Node) {
            return null;
        }

        return $this->item($metricsNode, $projectedNode);
    }

    private function item(Node $metricsNode, Node $node): ?MetricsExporterProjectionItem
    {
        $roles = array_values(
            $node
                ->roles
                ->filter(static fn (NodeRole $role): bool => in_array(
                    $role->status,
                    [LifecycleStatus::Provisioning, LifecycleStatus::Active],
                    strict: true,
                ))
                ->map(static fn (NodeRole $role): RoleName => $role->role)
                ->all(),
        );

        // A task VM Node never hosts an exporter: the hub filter lets only the Gateway reach it, so the
        // Metrics Node could not scrape it, and its enrollment skips the Metrics reconcile.
        $candidate = app(RouteRenderContext::class)->enrollment();
        $prospective = $candidate?->nodeId === $node->id && $candidate->platform === 'linux'
            && in_array($node->status, [LifecycleStatus::Active, LifecycleStatus::Provisioning, LifecycleStatus::Failed], true);
        $selection = $this->selector->select(
            $roles,
            ($prospective || $this->eligibility->allows($node)) && ! TaskVm::forNode($node) instanceof TaskVm,
            $this->preferences->get($node->id),
            $node->is($metricsNode),
        );

        return $selection->reason === ExporterSelectionReason::Ineligible
            ? null
            : new MetricsExporterProjectionItem($node, $selection);
    }
}
