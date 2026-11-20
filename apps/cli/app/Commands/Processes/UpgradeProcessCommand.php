<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Processes\UpgradeProcessRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class UpgradeProcessCommand extends ProcessCommand
{
    #[\Override]
    protected $signature = 'process:upgrade
        {process : Numeric process ID}
        {version : Upstream release version, for example 8.0.80; a leading v is allowed}
        {--idempotency-key= : Reuse a key to retry this Operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Upgrade a Process to another release of its artifact.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $processId = $this->positiveId('process', 'Process', 'process.id_invalid');

        if ($processId === null) {
            return self::FAILURE;
        }

        $version = $this->argument('version');

        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,64}\z/D', $version) !== 1) {
            return $this->renderGatewayFailure('process.version_unknown', 'A version is a release name such as 8.0.80.', details: ['field' => 'version']);
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $key = $this->option('idempotency-key');
        $operation = $this->sendWithProgress(
            $connector,
            new UpgradeProcessRequest($processId, $version, is_string($key) ? $key : null),
            OperationResponse::class,
            ['Upgrade Process', 'Requesting the upgrade', 'Upgrade accepted'],
        );

        return $operation instanceof OperationResponse
            ? $this->finishOperation($connector, $repository, $operation, 'Upgrade Process')
            : self::FAILURE;
    }
}
