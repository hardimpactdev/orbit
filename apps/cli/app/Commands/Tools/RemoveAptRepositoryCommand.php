<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tools\RemoveAptRepositoryRequest;
use Orbit\Sdk\Requests\Tools\ShowAptRepositoryRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Tools\AptRepositoryResponse;

final class RemoveAptRepositoryCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'tool:apt-repository-remove
        {repository : Numeric apt repository ID}
        {--yes : Skip the destructive confirmation prompt}
        {--idempotency-key= : Reuse a stable operation start key}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove an apt repository that no tool references.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $id = $this->positiveId('repository', 'Apt repository', 'tool.repository_id_invalid');
        if ($id === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        if ($this->option('yes') !== true) {
            $existing = $this->sendWithProgress($connector, new ShowAptRepositoryRequest($id), AptRepositoryResponse::class,
                ['Resolve apt repository', 'Loading apt repository', 'Loaded apt repository']);
            if (! $existing instanceof AptRepositoryResponse) {
                return self::FAILURE;
            }
            if (! $this->confirmAction(
                "Remove apt repository [{$existing->name}] from Node #{$existing->nodeId} by deleting its source, keyring and preferences?",
                'Apt repository removal cancelled.',
            )) {
                return self::FAILURE;
            }
        }
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new RemoveAptRepositoryRequest($id, $this->stringOption('idempotency-key')), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Remove apt repository #{$id}", 'Apt repository')
            : self::FAILURE;
    }
}
