<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\DetachInstanceNodeServiceTargetRequest;
use Orbit\Sdk\Requests\Instances\ListInstanceNodeServiceTargetsRequest;
use Orbit\Sdk\Responses\Instances\InstanceNodeServiceTargetsResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class DetachInstanceNodeServiceTargetCommand extends NodeServiceTargetCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:node-service:detach
        {instance? : Numeric Instance ID}
        {target? : Node service target ID}
        {--yes : Confirm removal of the recorded attachment}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Withdraw scoped Node service access and remove the attachment.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instance = $this->argument('instance') === null ? null : $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        if ($this->argument('instance') !== null && $instance === null) {
            return self::FAILURE;
        }
        $targetId = $this->argument('target') === null ? null : $this->suppliedTargetId();
        if ($this->argument('target') !== null && $targetId === null) {
            return self::FAILURE;
        }
        $instance ??= $this->instanceId($repository, $connectors);
        if ($instance === null) {
            return self::FAILURE;
        }
        $targetId ??= $this->selectTargetId($repository, $connectors, $instance);
        if ($targetId === null) {
            return self::FAILURE;
        }
        if (! $this->confirmAction("Delete Node service target record [{$targetId}] from Instance [{$instance}]? Its scoped access is withdrawn; environment and Node Process stay unchanged.", 'Node service target detach cancelled.',
            option: 'yes', requiredCode: 'instance.node_service_confirmation_required')) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $operation = $this->sendWithProgress($connector, new DetachInstanceNodeServiceTargetRequest($instance, $targetId, (string) Str::uuid()),
            OperationResponse::class, ['Detach Node service target', 'Detaching Node service target', 'Detached Node service target']);

        if (! $operation instanceof OperationResponse) {
            return self::FAILURE;
        }
        if (! $operation->terminal()) {
            $profile = $this->activeGatewayProfile($repository);

            return $profile === null ? self::FAILURE
                : $this->followOperation($connector, $profile, $operation, 'Detach Node service target', 'Node service detach');
        }
        if ($this->option('json') === true) {
            $this->writeJson($operation->toArray());
        } else {
            ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Node service target detached.', [
                'Target ID' => $targetId, 'Instance ID' => $instance, 'Operation' => $operation->id,
                'Status' => $operation->status, 'Request ID' => $operation->requestId,
            ]));
        }

        return self::SUCCESS;
    }

    private function suppliedTargetId(): ?int
    {
        $value = $this->argument('target');
        $id = is_string($value) && preg_match('/\A[0-9]+\z/D', $value) === 1
            ? filter_var(ltrim($value, '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if (! is_int($id)) {
            $this->renderGatewayFailure('instance.node_service_target_id_invalid', 'Node service target ID must be a positive integer written in ASCII digits.');

            return null;
        }

        return $id;
    }

    private function selectTargetId(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors, int $instanceId): ?int
    {
        if (! $this->consoleMode()->mayPrompt) {
            $this->renderGatewayFailure('instance.node_service_target_id_invalid', 'Node service target ID is required.');

            return null;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return null;
        }
        $response = $this->sendWithProgress($connector, new ListInstanceNodeServiceTargetsRequest($instanceId), InstanceNodeServiceTargetsResponse::class,
            ['List Node service targets', 'Loading Node service targets', 'Loaded Node service targets']);
        if (! $response instanceof InstanceNodeServiceTargetsResponse) {
            return null;
        }
        $rows = [];
        foreach ($response->targets as $target) {
            $rows[$target->id] = [(string) $target->id, (string) $target->processId, $target->protocol, (string) $target->port,
                $target->hostEnvKey, $target->portEnvKey];
        }

        return $this->selectRecord('Node service target', ['ID', 'Process', 'Protocol', 'Port', 'Host key', 'Port key'], $rows, 'instance.node_service_target_id_invalid');
    }
}
