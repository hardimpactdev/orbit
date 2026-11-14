<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceMachineName;
use App\Domain\Instances\InstanceState;
use App\Domain\Integrations\IntegrationAvailability;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/**
 * Admits the T3 restart of one container Instance for its machine name ([Restart for the machine name](/reference/conn#restart-for-the-machine-name)).
 * `read_at` is when the Gateway's thread read ended; the run's `restart_at` measures the window from it. It holds the Instance's lock, so no sleep, wake or other lifecycle work runs beside it. No credential enters the intent: the run
 * restarts T3 and reads only its public label, inside the container on T3's own port.
 */
final readonly class AdmitConnEnvironmentRestartAction
{
    public const string Kind = 'conn.environment_restart';

    public function __construct(private StartOperationAction $operations) {}

    public function execute(Instance $instance, InstanceRuntime $runtime, ConnEnvironment $environment, string $name, CarbonImmutable $readAt, ?string $triggeredBy, ?string $requestId): Operation
    {
        IntegrationAvailability::assertKind(self::Kind);
        if ($instance->status !== InstanceState::Active) {
            throw new ResourceOperationException('instance.lifecycle_not_active', "Instance {$instance->id} is not active.", 409);
        }

        return $this->operations->execute(self::Kind, 'instance', $instance->id, $runtime->node_id, [
            'instance_id' => $instance->id, 'conn_environment_id' => $environment->id,
            'intent' => ['instance_id' => $instance->id, 't3_port' => InstanceHostIntent::T3Port, 'name' => $name, 'machine_info' => InstanceMachineName::machineInfo($name), 'read_at' => $readAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'container' => ['project' => $runtime->incus_project, 'name' => $runtime->container_name]],
        ], $triggeredBy, $requestId, lockKey: OperationStore::lockKey('instance', $instance->id));
    }
}
