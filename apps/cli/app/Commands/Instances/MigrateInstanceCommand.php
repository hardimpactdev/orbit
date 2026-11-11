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
        {--force : Confirm the downtime and the retention of the old checkout}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Move a development checkout Instance into an Instance container.';

    #[\Override]
    protected $help = <<<'HELP'
Migrate moves one development checkout Instance into an Instance container on its Node: its branch, its changed, untracked and ignored files, and its Route. Its database stays where it is.

A Project's default Instance moves first. A failure before cutover leaves the checkout Instance serving if restoration succeeds. After cutover, check moved/restored in the Operation result.

The command is temporary: it goes when the last checkout Instance has moved. --json disables prompts and requires --force.
HELP;

    protected function writeOperationResult(OperationResponse $operation): void
    {
        $message = $operation->result['error_message'] ?? null;
        if (in_array($operation->errorCode, ['instance.migrate_retirement_blocked', 'instance.migrate_unpushed_commits', 'instance.migrate_stashes_present', 'instance.migrate_symlink_unsupported'], true) && is_string($message)) {
            $this->writeHumanMessage($message);
        }
    }

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
                "Move Instance [{$instance->name}] (#{$instance->id}) into a container with downtime and retention of its old checkout?",
                'Instance move cancelled.',
                option: 'force',
                requiredCode: 'instance.confirmation_required',
                requiredMessage: 'Use --force to confirm the downtime of the move and the retention of the old checkout.',
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
