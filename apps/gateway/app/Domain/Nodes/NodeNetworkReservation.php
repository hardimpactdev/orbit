<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Reserves a convergence target and the shared hub until its Operation settles. Every converge records the hub it may change and
 * whether it is a base converge (`node.converge` with `base: true`). A base intent never converges the network, the hub's own
 * included (NodeDesiredConvergeInput::baseIntent), so two base converges of different Nodes never conflict. Two converges of
 * different peers of the hub never conflict either: each network phase writes the hub's recorded peer set, the same for both, with
 * atomic file copies, one `wg syncconf` and UFW's own lock, prunes only by the bounds of its snapshot, and deletes obsolete UFW rules
 * by their spec, never by a number that the other converge's delete would shift (node-converge-hub-filter-absent.yml). So the nested-fleet
 * refresh converges nested-app-dev and nested-app-prod together (docs/reference/development-containers.md). A converge of the hub
 * Node itself, beside any other converge, still refuses unless both are base converges.
 */
final readonly class NodeNetworkReservation
{
    /** The recorded VPN hub of every converge. */
    public static function hub(): ?Node
    {
        return Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn))->first();
    }

    /** @param Closure(?Node):Operation $admit */
    public function hold(Node $node, bool $base, Closure $admit): Operation
    {
        return DB::transaction(function () use ($node, $base, $admit): Operation {
            $hub = self::hub();
            Node::query()->whereKey(array_values(array_unique([$node->id, $hub->id ?? $node->id])))->orderBy('id')->lockForUpdate()->get();
            $node->refresh();
            $open = Operation::query()->where('kind', 'node.converge')
                ->where(static function ($query): void {
                    $query->whereNotIn('status', OperationStatus::terminalValues())
                        ->orWhere(static fn ($expired) => $expired->where('status', OperationStatus::Failed)
                            ->where('error_code', 'operation.timed_out')->whereNotNull('lock_key'));
                })
                ->where(function ($query) use ($node, $hub): void {
                    $query->where('node_id', $node->id);
                    if ($hub !== null) {
                        $query->orWhere('input->_converge->network->hub_id', $hub->id);
                    }
                })->get();
            // A converge of the same Node always conflicts. Through the hub, two peers pass each other; beside a converge of the hub
            // Node itself, only two base converges pass. An Operation without the base mark counts as one with roles.
            $busy = $open->contains(static fn (Operation $operation): bool => $operation->node_id === $node->id
                || (in_array($hub?->id, [$node->id, $operation->node_id], true)
                    && (! $base || (ValidatedData::object(ValidatedData::object($operation->input['_converge'] ?? [])['network'] ?? [])['base'] ?? false) !== true)));
            if ($busy) {
                throw new ResourceOperationException('operation.target_busy', 'Another Operation owns the Node or VPN hub.', 409);
            }

            return $admit($hub);
        });
    }
}
