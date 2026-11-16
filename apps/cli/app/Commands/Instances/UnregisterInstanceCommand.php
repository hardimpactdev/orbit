<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Requests\Instances\UnregisterInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Orbit\Sdk\Responses\Instances\InstancesResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** The inverse of instance:register: Orbit forgets a host checkout Instance and keeps its folder (docs/cli/instance/unregister.mdx). */
final class UnregisterInstanceCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:unregister
        {instance? : Numeric Instance ID}
        {--yes : Confirm without prompting}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Make Orbit forget a host checkout Instance; the checkout folder and its files stay.';

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

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if ($this->option('yes') !== true) {
            $existing = $this->sendWithProgress($connector, new ShowInstanceRequest($instanceId), InstanceResponse::class,
                ['Resolve Instance', 'Loading Instance', 'Loaded Instance']);
            if (! $existing instanceof InstanceResponse) {
                return self::FAILURE;
            }
            if (! $this->confirmAction("Unregister Instance [{$existing->name}] (#{$existing->id})? Orbit forgets it; the checkout folder and its files stay.",
                'Instance unregister cancelled.')) {
                return self::FAILURE;
            }
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new UnregisterInstanceRequest($instanceId, $this->stringOption('idempotency-key')), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Unregister Instance', 'Instance unregister')
            : self::FAILURE;
    }
}
