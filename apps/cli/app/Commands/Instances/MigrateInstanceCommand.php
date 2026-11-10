<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\MigrateInstanceRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** Temporary: it goes when the last checkout Instance has moved into a container (docs/reference/old-paths.md). */
final class MigrateInstanceCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:migrate
        {instance : Numeric Instance ID}
        {--force : Confirm the downtime and the deletion of the checkout}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Move a development checkout Instance into an Instance container.';

    #[\Override]
    protected $help = <<<'HELP'
Migrate moves one development checkout Instance into an Instance container on its Node: its branch, its changed, untracked and ignored files, and its Route. Its database stays where it is.

A Project's default Instance moves first. A move that fails leaves the checkout Instance serving as before.

The command is temporary: it goes when the last checkout Instance has moved. --json disables prompts and requires --force.
HELP;

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

        if ($this->option('force') !== true) {
            $instance = $this->sendWithProgress($connector, new ShowInstanceRequest($instanceId), InstanceResponse::class,
                ['Resolve Instance', 'Loading Instance', 'Loaded Instance']);
            if (! $instance instanceof InstanceResponse || ! $this->confirmAction(
                "Move Instance [{$instance->name}] (#{$instance->id}) into a container with downtime and deletion of its checkout?",
                'Instance move cancelled.',
                option: 'force',
                requiredCode: 'instance.confirmation_required',
                requiredMessage: 'Use --force to confirm the downtime of the move and the deletion of the checkout.',
            )) {
                return self::FAILURE;
            }
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new MigrateInstanceRequest(
            instanceId: $instanceId, idempotencyKey: $this->stringOption('idempotency-key'),
        ), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Move Instance', 'Instance move')
            : self::FAILURE;
    }
}
