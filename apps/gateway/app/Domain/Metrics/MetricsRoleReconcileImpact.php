<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Models\Node;

/** CI/database change only this Node's selection; they change no PHP pools, routes or Metrics owner. */
final readonly class MetricsRoleReconcileImpact
{
    public function __construct(private MetricsExporterProjection $exporters, private ServiceMetricsProjection $services) {}

    /** @return array{metrics_node_id:int,selected:bool,caddy:bool,fpm:bool}|null */
    public function capture(Node $node, RoleName $role): ?array
    {
        if (! NodeRoleDirectInput::supports($role)) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This Metrics comparison supports CI and database roles only.', 422);
        }
        $owners = Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles',
            static fn ($query) => $query->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active))->limit(2)->get();
        if ($owners->isEmpty()) {
            return null;
        }
        if ($owners->count() !== 1) {
            throw new ResourceOperationException('metrics.assignment_missing', 'Role reconciliation requires one Metrics owner.', 409);
        }
        $owner = $owners->sole();
        $item = $this->exporters->forNode($owner, $node);
        $service = $this->services->forNode($owner, $node);

        return ['metrics_node_id' => $owner->id, 'selected' => $item?->selection->selected ?? false,
            'caddy' => $service->caddy, 'fpm' => $service->fpm];
    }

    /** @param array{metrics_node_id:int,selected:bool,caddy:bool,fpm:bool}|null $before
     * @param  array{metrics_node_id:int,selected:bool,caddy:bool,fpm:bool}|null  $after
     * @return array{required:bool,before_sha256:string,after_sha256:string}
     */
    public function compare(?array $before, ?array $after): array
    {
        return ['required' => $before !== $after,
            'before_sha256' => hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)),
            'after_sha256' => hash('sha256', json_encode($after, JSON_THROW_ON_ERROR))];
    }
}
