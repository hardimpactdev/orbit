<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use Orbit\Sdk\Requests\Instances\AttachInstanceNodeServiceTargetRequest;
use Orbit\Sdk\Responses\Instances\InstanceNodeServiceTargetResponse;

final class AttachInstanceNodeServiceTargetCommand extends NodeServiceTargetCommand
{
    #[\Override]
    protected $signature = 'instance:node-service:attach
        {instance? : Numeric Instance ID}
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
        $port = $this->option('port');
        $targetPort = $port === null ? null : filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($targetPort === false) {
            return $this->renderGatewayFailure('validation.failed', 'The port must be from 1 through 65535.');
        }
        $instance = $this->argument('instance') === null ? null : $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        if ($this->argument('instance') !== null && $instance === null) {
            return self::FAILURE;
        }
        $process = $this->stringOption('process');
        $processId = $process === null ? null : filter_var($process, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($processId === false) {
            return $this->renderGatewayFailure('validation.failed', 'Process ID must be a positive integer.');
        }
        $instance ??= $this->instanceId($repository, $connectors);
        if ($instance === null) {
            return self::FAILURE;
        }
        $processId ??= $this->processId($repository, $connectors, $instance);
        if ($processId === null) {
            return self::FAILURE;
        }
        $hostKey = $this->environmentKey('host-env-key', 'Host environment key', 'instance.node_service_host_key_required');
        if ($hostKey === null) {
            return self::FAILURE;
        }
        $portKey = $this->environmentKey('port-env-key', 'Port environment key', 'instance.node_service_port_key_required', $hostKey);
        if ($portKey === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $target = $this->sendWithProgress($connector, new AttachInstanceNodeServiceTargetRequest($instance, $processId,
            $this->stringOption('protocol') ?? '', $targetPort, $hostKey, $portKey), InstanceNodeServiceTargetResponse::class,
            ['Attach Node service target', 'Attaching Node service target', 'Attached Node service target'],
            static fn (InstanceNodeServiceTargetResponse $result): ProgressOutcome => $result->changed
                ? new ProgressOutcome(ProgressState::Success, 'Attached Node service target')
                : new ProgressOutcome(ProgressState::Skipped, 'Already attached (instance.node_service_target_unchanged)'));

        return $target instanceof InstanceNodeServiceTargetResponse ? $this->renderTarget($target, 'attached') : self::FAILURE;
    }
}
