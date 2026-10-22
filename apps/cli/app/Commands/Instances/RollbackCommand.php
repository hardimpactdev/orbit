<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Deployments\RollbackInstanceRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** Starts an `instance.rollback` operation and follows it until it ends. Ctrl-C stops following, not the rollback. */
final class RollbackCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:rollback
        {instance : Numeric instance ID}
        {--release= : Retained release name to select}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Select one retained production Instance release.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $release = $this->stringOption('release');

        if ($release === null) {
            return $this->renderGatewayFailure(
                'deployment.release_required',
                'A retained release name is required.',
            );
        }

        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        $connector = $connectors->make($profile);
        $key = $this->option('idempotency-key');
        $operation = $this->send($connector, new RollbackInstanceRequest($instanceId, $release, is_string($key) ? $key : null), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Roll back Instance [{$instanceId}]", 'Rollback')
            : self::FAILURE;
    }
}
