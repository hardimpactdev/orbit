<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Illuminate\Support\Str;
use Orbit\Sdk\GatewayApiException;
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
        {--yes : Confirm withdrawal and removal of the attachment}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Withdraw a Node service attachment from an Instance, then remove its record.';

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
        if (! $this->confirmAction("Detach Node service target [{$targetId}] from Instance [{$instance}]? Withdraw its container access; keep environment and Process.", 'Node service target detach cancelled.',
            option: 'yes', requiredCode: 'instance.node_service_confirmation_required')) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        try {
            $operation = $this->sendOrThrow($connector, new DetachInstanceNodeServiceTargetRequest($instance, $targetId, (string) Str::uuid()), OperationResponse::class);
        } catch (GatewayApiException $exception) {
            return $this->renderApiFailure($exception);
        }

        if (! $operation->terminal()) {
            return $this->followOperation($connector, $profile, $operation, 'Detach Node service target', 'Node service withdrawal');
        }
        if (! $operation->succeeded()) {
            if ($this->option('json') === true) {
                $this->writeJson($operation->toArray());

                return self::FAILURE;
            }

            return $this->renderGatewayFailure($operation->errorCode ?? 'operation.native_failed',
                'Node service withdrawal failed; the attachment remains for retry.', $operation->requestId);
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

    protected function writeOperationResult(OperationResponse $operation): void
    {
        $this->writeHumanMessage($operation->succeeded()
            ? 'Node service target detached after access withdrawal.'
            : 'The attachment remains for retry; access may already have been withdrawn. Environment and Process stay unchanged.');
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
