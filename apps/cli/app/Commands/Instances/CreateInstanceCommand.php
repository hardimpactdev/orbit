<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class CreateInstanceCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:create
        {project : Numeric Project ID}
        {node : Numeric node ID}
        {name : Instance name; default, or the Project default branch name, means the default development source}
        {--root= : Optional relative web-root override}
        {--domain= : Optional explicit Route domain}
        {--branch= : Optional explicit source branch}
        {--database-server= : Database server that gets the new Instance database before setup}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--wait-active : Follow the clone\'s completion too, and exit 0 only when the Instance is active}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a development Instance on an app-dev Node.';

    #[\Override]
    protected $help = <<<'HELP'
Creates a development Instance. New production Instances require a candidate. Use instance:clone.
With a Database server, Orbit creates the Instance's database there before the setup steps run.
A container clone's create ends when its thread is usable; its completion (completion_operation_id) makes it active.
HELP;

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $projectId = $this->positiveId('project', 'Project', 'project.id_invalid');

        if ($projectId === null) {
            return self::FAILURE;
        }

        $nodeId = $this->positiveId('node', 'Node', 'node.id_invalid');

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $name = $this->stringArgument('name', 'Instance name', 'instance.name_required');

        if ($name === null) {
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
        $operation = $this->send($connector, new CreateInstanceRequest(
            projectId: $projectId, nodeId: $nodeId, name: $name,
            root: $this->stringOption('root'), domain: $this->stringOption('domain'),
            branch: $this->stringOption('branch'), databaseServer: $this->stringOption('database-server'),
            idempotencyKey: $this->stringOption('idempotency-key'),
        ), OperationResponse::class);

        if (! $operation instanceof OperationResponse) {
            return self::FAILURE;
        }
        $code = $this->followOperation($connector, $profile, $operation, 'Create Instance', 'Instance creation');
        $created = $this->followedOperation;
        if ($this->option('wait-active') !== true || $code !== self::SUCCESS || ! $created instanceof OperationResponse) {
            return $code;
        }

        return $this->waitActive($connector, $profile, $created);
    }

    /** The create's result names the clone's completion; the human summary names it too. */
    protected function writeOperationResult(OperationResponse $operation): void
    {
        $completion = self::completionId($operation);
        if ($completion !== null) {
            $this->writeHumanMessage("Completion: {$completion}");
            if ($this->option('wait-active') !== true) {
                $this->writeHumanMessage("Active when it succeeds: orbit operation:show {$completion}");
            }
        }
    }

    /** With --wait-active, the JSON is the completion's when there is one: the last Operation the command follows. */
    protected function writesOperationJson(OperationResponse $operation): bool
    {
        return $this->option('wait-active') !== true || $operation->kind !== 'instance.create' || self::completionId($operation) === null;
    }

    /** "Fully set up" is only the Instance's `active` status (docs/cli/instance/create.mdx#fully-set-up). */
    private function waitActive(GatewayConnector $connector, GatewayProfile $profile, OperationResponse $created): int
    {
        $completion = self::completionId($created);
        if ($completion !== null) {
            $started = $this->send($connector, new ShowOperationRequest($completion), OperationResponse::class);
            if (! $started instanceof OperationResponse) {
                return self::FAILURE;
            }
            $code = $this->followOperation($connector, $profile, $started, 'Complete Instance', 'Instance completion');
            if ($code !== self::SUCCESS) {
                return $code;
            }
        }
        $instance = $this->send($connector, new ShowInstanceRequest($created->targetId), InstanceResponse::class);
        if (! $instance instanceof InstanceResponse) {
            return self::FAILURE;
        }
        if ($instance->status === 'active') {
            return self::SUCCESS;
        }
        $this->writeHumanMessage("Instance {$instance->id} is {$instance->status}, not active.");

        return self::FAILURE;
    }

    private static function completionId(OperationResponse $operation): ?int
    {
        $id = $operation->result['completion_operation_id'] ?? null;

        return $operation->kind === 'instance.create' && is_int($id) ? $id : null;
    }
}
