<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\SleepInstanceRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class SleepInstanceCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:sleep
        {instance : Numeric Instance ID}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Stop a development container Instance now; its Route shows a wake page. Does not check T3: a running agent turn is cut.';

    protected function writeOperationResult(OperationResponse $operation): void
    {
        if (($operation->result['t3_turn'] ?? null) === 'not_checked') {
            $this->writeHumanMessage('T3 was not checked: a running agent turn, if any, was cut. T3 continues it at the next wake.');
        }
    }

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        if ($instanceId === null) {
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
        $key = $this->stringOption('idempotency-key') ?? bin2hex(random_bytes(16));
        $operation = $this->send($connector, new SleepInstanceRequest($instanceId, $key), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Put the Instance to sleep', 'Instance sleep')
            : self::FAILURE;
    }
}
