<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\DetachInstanceNodeServiceTargetRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class DetachInstanceNodeServiceTargetCommand extends NodeServiceTargetCommand
{
    #[\Override]
    protected $signature = 'instance:node-service:detach
        {instance? : Numeric Instance ID}
        {target? : Node service target ID}
        {--yes : Confirm removal of the recorded attachment}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Delete a Node service attachment record without changing network access or runtime.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instance = $this->argument('instance') === null ? null : $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        if ($this->argument('instance') !== null && $instance === null) {
            return self::FAILURE;
        }
        $targetId = $this->argument('target') === null ? null : $this->positiveId('target', 'Node service target', 'instance.node_service_target_id_invalid');
        if ($this->argument('target') !== null && $targetId === null) {
            return self::FAILURE;
        }
        $instance ??= $this->instanceId($repository, $connectors);
        if ($instance === null) {
            return self::FAILURE;
        }
        if ($targetId === null) {
            return $this->renderGatewayFailure('instance.node_service_target_id_invalid', 'Node service target ID is required.');
        }
        if (! $this->confirmAction("Delete Node service target record [{$targetId}] from Instance [{$instance}]? Network access, environment and Process stay unchanged.", 'Node service target detach cancelled.',
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
}
