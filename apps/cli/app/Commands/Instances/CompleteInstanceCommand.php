<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\CompleteInstanceRequest;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Responses\Instances\InstancesResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class CompleteInstanceCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:complete
        {instance? : Numeric Instance ID}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Finish the setup of a container clone and make it active.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        if ($this->argument('instance') === null && $this->consoleMode()->mayPrompt) {
            $connector = $this->gatewayConnector($repository, $connectors);
            if ($connector === null) {
                return self::FAILURE;
            }
            $instances = $this->sendWithProgress($connector, new ListInstancesRequest,
                InstancesResponse::class, ['List Instances', 'Loading Instances', 'Loaded Instances']);
            if (! $instances instanceof InstancesResponse) {
                return self::FAILURE;
            }
            $rows = [];
            foreach ($instances->instances as $candidate) {
                $rows[$candidate->id] = [(string) $candidate->id, $candidate->name, $candidate->status];
            }
            $this->input->setArgument('instance', (string) $this->commandPrompts()->selectEntity('Instance', ['ID', 'Name', 'Status'], $rows));
        }
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $connector = $connectors->make($profile);
        $key = $this->option('idempotency-key');
        $operation = $this->send($connector, new CompleteInstanceRequest($instanceId, is_string($key) ? $key : null), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Complete Instance', 'Instance completion')
            : self::FAILURE;
    }
}
