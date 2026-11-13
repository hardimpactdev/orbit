<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceState;
use App\Domain\Integrations\IntegrationAvailability;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/**
 * Admits the renewal of the Gateway's admin session on a container Instance's T3 server
 * ([Instance servers](/reference/conn#instance-servers)). The run issues the session in that Instance's own container, so a clone
 * never gets another Instance's session. No credential enters the intent: the run needs no old session, so an ended one renews too.
 */
final readonly class AdmitConnRenewalAction
{
    public const string Kind = 'conn.environment_renew';

    public function __construct(private StartOperationAction $operations, private NodeAccessAuthorizer $nodes) {}

    /** Without a caller, the hourly sweep admits it. */
    public function execute(ConnEnvironment $environment, ?Node $caller = null): Operation
    {
        IntegrationAvailability::assertKind(self::Kind);
        $gateway = $this->nodes->activeGatewayId() ?? throw new ResourceOperationException('conn.gateway_missing', 'Renewal needs one active Gateway Node.', 409);

        return DB::transaction(function () use ($environment, $gateway, $caller): Operation {
            $environment = ConnEnvironment::query()->lockForUpdate()->findOrFail($environment->id);
            $instance = $environment->instance;
            $runtime = $instance === null ? null : InstanceRuntime::query()->where('instance_id', $instance->id)->first();
            if ($instance === null || ! $runtime instanceof InstanceRuntime || ! InstanceContainer::runsT3($runtime)) {
                throw new ResourceOperationException('conn.environment_not_instance', "{$environment->label} is not the T3 server of a container Instance; its host renews its session.", 409);
            }
            if ($instance->status !== InstanceState::Active) {
                throw new ResourceOperationException('instance.lifecycle_not_active', "Instance {$instance->id} is not active.", 409);
            }
            $node = Node::query()->findOrFail($runtime->node_id);

            return $this->operations->execute(self::Kind, 'conn_environment', $environment->id, $gateway, [
                'conn_environment_id' => $environment->id, 'instance_id' => $instance->id, 'caller_node_id' => $caller?->id,
                'intent' => ['instance_id' => $instance->id, 'environment_id' => $environment->environment_id, 'url' => $environment->url,
                    't3' => InstanceHostIntent::AgentFolder.'/current/agent/bin/t3',
                    'container' => ['project' => $runtime->incus_project, 'name' => $runtime->container_name],
                    'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($node)],
            ], $caller === null ? 'schedule' : 'operator', null, lockKey: OperationStore::lockKey('conn_environment', $environment->id));
        });
    }
}
