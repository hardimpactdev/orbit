<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\RenameInstanceRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class RenameInstanceCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:rename
        {instance : Numeric Instance ID}
        {--branch= : Branch already checked out on the Node}
        {--domain= : New domain for the Instance Route}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Record a development branch rename and move its Route.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $id = $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        if ($id === null) {
            return self::FAILURE;
        }
        if ($this->option('branch') === '' || $this->option('domain') === '') {
            return $this->renderGatewayFailure('validation.failed', 'Branch and domain must be nonempty when supplied.');
        }
        $branch = $this->stringOption('branch');
        $domain = $this->stringOption('domain');
        if ($branch === null && $domain === null) {
            return $this->renderGatewayFailure('validation.failed', 'Provide --branch or --domain.');
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new RenameInstanceRequest(
            $id, $branch, $domain, $this->stringOption('idempotency-key'),
        ), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Rename Instance', 'Instance rename')
            : self::FAILURE;
    }
}
