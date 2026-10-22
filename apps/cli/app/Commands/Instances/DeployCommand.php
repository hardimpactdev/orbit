<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Deployments\DeployInstanceRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** Starts an `instance.deploy` operation and follows it until it ends. Ctrl-C stops following, not the deploy. */
final class DeployCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:deploy
        {instance : Numeric instance ID}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Deploy a production Instance or an app-dev default Instance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
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
        $operation = $this->send($connector, new DeployInstanceRequest($instanceId, is_string($key) ? $key : null), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Deploy Instance [{$instanceId}]", 'Deployment')
            : self::FAILURE;
    }
}
