<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\FleetReboot;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Admits one `fleet.reboot` pass (ADR 0251): the full intent of every Node it visits, in order. Swamp reads on each Node
 * whether it is due, drains it, reboots it and checks it, one Node at a time. The pass, a fleet rollout and a Gateway
 * release never overlap: each refuses while another runs.
 */
final readonly class AdmitFleetRebootAction
{
    public function __construct(private FleetReboot $reboot, private GatewayServingHost $gateway, private StartOperationAction $start) {}

    /**
     * @param  list<int>|null  $nodeIds  The Nodes to visit; every managed Linux Node when null.
     * @param  bool  $window  Whether each Node waits for the window. The nightly pass does; an operator's pass runs at once.
     */
    public function execute(?array $nodeIds = null, bool $window = false): Operation
    {
        if (($replay = app(OperationIdempotency::class)->replay(FleetReboot::Kind)) !== null) {
            return $replay;
        }

        return DB::transaction(fn (): Operation => $this->admit($nodeIds, $window));
    }

    /** @param list<int>|null $nodeIds */
    private function admit(?array $nodeIds, bool $window): Operation
    {
        $root = $this->gateway->nodeId();
        if ($root === null || ! Config::boolean('fleet.reboot')) {
            throw new ResourceOperationException('fleet.reboot_not_ready', 'Fleet reboots are off on this Gateway.', 409);
        }
        if (FleetReboot::open() instanceof Operation) {
            throw new ResourceOperationException('fleet.reboot_busy', 'A fleet reboot pass is already running.', 409);
        }
        if (Operation::query()->where('kind', 'fleet.rollout')->whereNotIn('status', OperationStatus::terminalValues())->exists()
            || GatewayRelease::query()->whereIn('outcome', [GatewayRelease::Running, GatewayRelease::Queued])->get()
                ->contains(static fn (GatewayRelease $record): bool => ! $record->awaitingPublication())) {
            throw new ResourceOperationException('fleet.reboot_waiting', 'A fleet rollout or a Gateway release runs; the pass waits for it.', 409);
        }
        $members = $this->reboot->members();
        if ($nodeIds !== null) {
            $members = array_values(array_filter($members, static fn (Node $node): bool => in_array($node->id, $nodeIds, true)));
            if (count($members) !== count(array_unique($nodeIds))) {
                throw new ResourceOperationException('fleet.reboot_not_ready', 'A requested Node is not a managed Linux Node.', 409);
            }
        }
        $nodes = [];
        $order = [];
        $taskHosts = [];
        foreach ($members as $node) {
            $intent = $this->reboot->node($node);
            // A busy task host is only read, so its due time is known, and then waits; its work is not held off.
            $intent['defer'] = $intent['task_project'] !== null ? $this->reboot->busy($node) : null;
            $nodes[(string) $node->id] = $intent;
            $order[] = $node->id;
            if ($intent['task_project'] !== null && $intent['defer'] === null) {
                $taskHosts[] = $node->id;
            }
        }
        if ($order === []) {
            throw new ResourceOperationException('fleet.reboot_not_ready', 'No managed Linux Node to visit.', 409);
        }

        // Its steps run on the Swamp Node, which owns the pass, as it owns a rollout. One pass runs at a time.
        return $this->start->execute(FleetReboot::Kind, 'fleet', $root, SwampNode::node()->id, [
            'intent' => ['window' => [...FleetReboot::Window, 'enforced' => $window], 'drain_seconds' => Config::integer('fleet.reboot_drain_seconds'),
                'order' => $order, 'nodes' => $nodes, 'ci_repositories' => FleetReboot::ciRepositories()],
            'execution_secrets' => [],
            'task_hosts' => $taskHosts,
            'participant_node_ids' => array_values(array_unique([$root, SwampNode::id(), ...$order])),
        ], 'swamp', null, lockKey: OperationStore::lockKey('fleet_reboot', $root));
    }
}
