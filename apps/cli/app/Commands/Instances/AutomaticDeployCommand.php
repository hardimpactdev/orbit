<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Deployments\AutomaticDeployInstanceRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** Starts an `instance.deploy` operation and follows it until it ends. Ctrl-C stops following, not the deploy. */
final class AutomaticDeployCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:deploy:automatic
        {instance : Numeric instance ID}
        {--commit= : Required full commit SHA on the deployment branch}
        {--idempotency-key= : Required stable key for this automatic deploy}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Start a trusted automatic production deploy at a pinned commit.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $commit = $this->requiredStringOption('commit', 'A full commit SHA', 'deployment.commit_required');
        if ($commit === null) {
            return self::FAILURE;
        }
        $key = $this->requiredStringOption('idempotency-key', 'A stable request key', 'operation.idempotency_key_required');
        if ($key === null) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        $connector = $connectors->make($profile);
        $operation = $this->send($connector, new AutomaticDeployInstanceRequest($instanceId, $commit, $key), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Deploy Instance [{$instanceId}]", 'Deployment')
            : self::FAILURE;
    }
}
