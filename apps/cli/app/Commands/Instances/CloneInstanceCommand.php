<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\CloneInstanceRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class CloneInstanceCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:clone
        {candidate : Numeric candidate Instance ID}
        {node : Numeric destination Node ID}
        {name : Target production Instance name}
        {--preview-name= : Required preview name to combine with the production Node TLD}
        {--branch= : Optional target branch; omission inherits the candidate branch}
        {--sqlite-source-path= : Optional SQLite database path on the candidate}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Clone a candidate into a prepared production Instance.';

    #[\Override]
    protected $help = <<<'HELP'
The candidate supplies source, stored environment values, and an optional SQLite snapshot. The Project supplies production Process and Schedule definitions; candidate-specific overrides are not copied.

Provision the production Node with node:add --tld before cloning. Clean application state on the target only. Use env:update and env:sync for target configuration, then configure deploy steps with instance:deploy-step:create and deploy with instance:deploy.
HELP;

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $candidateId = $this->positiveId('candidate', 'Candidate', 'instance.candidate_id_invalid');

        if ($candidateId === null) {
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

        $previewName = $this->stringOption('preview-name');

        if ($previewName === null) {
            return $this->renderGatewayFailure(
                'instance.preview_name_required',
                'Preview name is required.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new CloneInstanceRequest(
            candidateId: $candidateId, nodeId: $nodeId, name: $name, previewName: $previewName,
            branch: $this->stringOption('branch'), sqliteSourcePath: $this->stringOption('sqlite-source-path'),
            idempotencyKey: $this->stringOption('idempotency-key'),
        ), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Clone Instance', 'Instance clone')
            : self::FAILURE;
    }
}
