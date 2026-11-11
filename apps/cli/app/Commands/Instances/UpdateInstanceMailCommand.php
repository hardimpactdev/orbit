<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\UpdateInstanceMailRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class UpdateInstanceMailCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:mail:update
        {instance : Numeric Instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Apply the development mail default without changing other environment lines.';

    protected function writeOperationResult(OperationResponse $operation): void
    {
        if (($operation->result['reason'] ?? null) === 'own_mail_settings_kept') {
            $this->writeHumanMessage('Own mail settings kept.');
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
        $operation = $this->send($connector, new UpdateInstanceMailRequest($instanceId, bin2hex(random_bytes(16))), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Update Instance mail', 'Instance mail update')
            : self::FAILURE;
    }
}
