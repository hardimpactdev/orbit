<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tools\AddAptRepositoryRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class AddAptRepositoryCommand extends ToolCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'tool:apt-repository-add
        {--node= : Numeric target node ID}
        {--name= : Repository name, also the name Tools reference}
        {--definition= : Signed apt repository definition as a JSON object: uris, suites, components, architectures and signing_key}
        {--idempotency-key= : Reuse a stable operation start key}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Publish a signed apt repository on a node, or publish it again.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $nodeId = $this->nodeId();
        if ($nodeId === null) {
            return self::FAILURE;
        }
        $name = $this->stringOption('name');
        if ($name === null) {
            $this->renderGatewayFailure('tool.repository_name_required', 'Repository name is required.');

            return self::FAILURE;
        }
        $definition = $this->repositoryDefinition($this->option('definition'));
        if ($definition === false) {
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
        $operation = $this->send($connector, new AddAptRepositoryRequest($nodeId, $name, $definition, $this->stringOption('idempotency-key')), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Add apt repository [{$name}]", 'Apt repository')
            : self::FAILURE;
    }
}
