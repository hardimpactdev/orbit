<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\AgentTools;
use App\Domain\Fleet\FleetReboot;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Admits one `fleet.agent_tools` run (ADR 0255): the T3 hosts and the model router, with what Swamp needs to reach and update
 * each. Swamp updates the hosts at once and the router beside them; a host or the router that fails never stops the others.
 */
final readonly class AdmitAgentToolsAction
{
    public function __construct(private AgentTools $tools, private GatewayServingHost $gateway, private StartOperationAction $start) {}

    public function execute(): Operation
    {
        if (($replay = app(OperationIdempotency::class)->replay(AgentTools::Kind)) !== null) {
            return $replay;
        }

        return DB::transaction(fn (): Operation => $this->admit());
    }

    private function admit(): Operation
    {
        $root = $this->gateway->nodeId();
        if ($root === null || ! Config::boolean('fleet.agent_tools')) {
            throw new ResourceOperationException('fleet.agent_tools_not_ready', 'Agent tool updates are off on this Gateway.', 409);
        }
        if (AgentTools::open() instanceof Operation) {
            throw new ResourceOperationException('fleet.agent_tools_busy', 'An agent tool update is already running.', 409);
        }
        // A reboot pass may reboot a host in the middle of an install; the update waits for the next day.
        if (FleetReboot::open() instanceof Operation) {
            throw new ResourceOperationException('fleet.agent_tools_waiting', 'A fleet reboot pass is running.', 409);
        }
        $hosts = $this->tools->hosts();
        $router = $this->tools->router();
        if ($hosts === [] && $router === null) {
            throw new ResourceOperationException('fleet.agent_tools_not_ready', 'No T3 host and no model router to update.', 409);
        }

        // Its steps run on the Swamp Node, as a fleet reboot pass does. One run at a time.
        return $this->start->execute(AgentTools::Kind, 'fleet', $root, SwampNode::node()->id, [
            'intent' => ['packages' => array_values(Config::array('fleet.agent_tools_packages')), 'hosts' => $hosts, 'router' => $router],
            'execution_secrets' => [],
            'participant_node_ids' => array_values(array_unique([$root, SwampNode::id(), ...array_column($hosts, 'node_id'),
                ...($router === null ? [] : [ValidatedData::integer($router['node_id'])])])),
        ], 'swamp', null, lockKey: OperationStore::lockKey('fleet_agent_tools', $root));
    }
}
