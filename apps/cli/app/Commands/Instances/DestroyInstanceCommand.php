<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\DestroyInstanceRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class DestroyInstanceCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:destroy
        {instance : Numeric instance ID}
        {--yes : Confirm removal without prompting}
        {--force : Delete dirty or unpublished source after identity checks}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy an Instance.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
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
            $isProduction = $existing->productionHome !== null;
            $effect = $isProduction
                ? 'delete its production content, Route and runtime'
                : 'delete its owned development source, Route and runtime';
            if ($this->option('force') === true && ! $isProduction) {
                $effect .= ', including dirty or unpublished work and registered linked worktrees';
            }
            if (! $this->confirmAction("Remove Instance [{$existing->name}] (#{$existing->id}) and {$effect}?", 'Instance removal cancelled.')) {
                return self::FAILURE;
            }
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new DestroyInstanceRequest(
            $instanceId,
            force: $this->option('force') === true ? true : null,
            idempotencyKey: $this->stringOption('idempotency-key'),
        ), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Remove Instance', 'Instance removal')
            : self::FAILURE;
    }
}
