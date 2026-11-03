<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Closure;
use Illuminate\Support\Facades\DB;

/** Both footprint-writing parents reserve their Node and shared hub until their Operation settles. */
final readonly class NodeNetworkReservation
{
    /** @param Closure(?Node):Operation $admit */
    public function hold(Node $node, Closure $admit): Operation
    {
        return DB::transaction(function () use ($node, $admit): Operation {
            $hub = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn))->first();
            Node::query()->whereKey(array_values(array_unique([$node->id, $hub->id ?? $node->id])))->orderBy('id')->lockForUpdate()->get();
            $node->refresh();
            $busy = Operation::query()->whereIn('kind', ['node.converge', 'fleet.recipe'])
                ->where(static function ($query): void {
                    $query->whereNotIn('status', OperationStatus::terminalValues())
                        ->orWhere(static fn ($expired) => $expired->where('status', OperationStatus::Failed)
                            ->where('error_code', 'operation.timed_out')->whereNotNull('lock_key'));
                })
                ->where(function ($query) use ($node, $hub): void {
                    $query->where('node_id', $node->id);
                    if ($hub !== null) {
                        $query->orWhere('input->_converge->network->hub_id', $hub->id)
                            ->orWhere('input->footprint->network->hub_id', $hub->id);
                    }
                })->exists();
            if ($busy) {
                throw new ResourceOperationException('operation.target_busy', 'Another Operation owns the Node or VPN hub.', 409);
            }

            return $admit($hub);
        });
    }
}
