<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\SwitchInstanceServerRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class SwitchInstanceServerCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:server:switch
        {instance : Numeric Instance ID}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Switch an existing T3 container Instance to the Conn Server.';

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
        $operation = $this->send($connector, new SwitchInstanceServerRequest($instanceId, $key), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Switch the container server', 'Instance server switch')
            : self::FAILURE;
    }
}
