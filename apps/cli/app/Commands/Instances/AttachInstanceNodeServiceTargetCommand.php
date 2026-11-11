<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\AttachInstanceNodeServiceTargetRequest;
use Orbit\Sdk\Responses\Instances\InstanceNodeServiceTargetResponse;

final class AttachInstanceNodeServiceTargetCommand extends NodeServiceTargetCommand
{
    #[\Override]
    protected $signature = 'instance:node-service:attach
        {instance : Numeric Instance ID}
        {--process= : Registered Orbit Node Process ID}
        {--protocol=tcp : Target protocol}
        {--port= : Published TCP port; required only when the Process publishes multiple ports}
        {--host-env-key= : Environment key for the bridge address}
        {--port-env-key= : Environment key for the target port}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Attach a Node service target before migrating a checkout Instance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instance = $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        $process = $this->requiredStringOption('process', 'Node Process ID', 'instance.node_service_process_required');
        $port = $this->stringOption('port');
        $hostKey = $this->requiredStringOption('host-env-key', 'Host environment key', 'instance.node_service_host_key_required');
        $portKey = $this->requiredStringOption('port-env-key', 'Port environment key', 'instance.node_service_port_key_required');
        if ($instance === null || $process === null || $hostKey === null || $portKey === null) {
            return self::FAILURE;
        }
        $processId = filter_var($process, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $targetPort = $port === null ? null : filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if (! is_int($processId) || $targetPort === false) {
            $this->renderGatewayFailure('validation.failed', 'Process ID must be positive and the port must be from 1 through 65535.');

            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $target = $this->sendWithProgress($connector, new AttachInstanceNodeServiceTargetRequest($instance, $processId,
            $this->stringOption('protocol') ?? '', $targetPort, $hostKey, $portKey), InstanceNodeServiceTargetResponse::class,
            ['Attach Node service target', 'Attaching Node service target', 'Attached Node service target']);

        return $target instanceof InstanceNodeServiceTargetResponse ? $this->renderTarget($target, 'attached') : self::FAILURE;
    }
}
