<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Metrics\ResetMetricsCredentialsRequest;
use Orbit\Sdk\Requests\Metrics\ShowMetricsCredentialsRequest;
use Orbit\Sdk\Responses\Metrics\MetricsCredentialsResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class CredentialsMetricsCommand extends MetricsCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'metrics:credentials {--reset : Reset the password} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show Metrics credentials.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->connector($repository, $factory);
        if ($connector === null) {
            return self::FAILURE;
        }

        $reset = $this->option('reset') === true;
        if ($reset) {
            $operation = $this->sendWithProgress($connector, new ResetMetricsCredentialsRequest, OperationResponse::class,
                ['Reset Metrics credentials', 'Requesting credential reset', 'Credential reset accepted']);
            $profile = $this->activeGatewayProfile($repository);
            if (! $operation instanceof OperationResponse || $profile === null) {
                return self::FAILURE;
            }
            $result = $this->followOperation($connector, $profile, $operation, 'Metrics credentials', 'Reset');
            if ($result !== self::SUCCESS) {
                return $result;
            }
            if ($this->option('json') === true) {
                return self::SUCCESS;
            }
        }
        $response = $this->sendWithProgress($connector, new ShowMetricsCredentialsRequest, MetricsCredentialsResponse::class,
            ['Show Metrics credentials', 'Loading Metrics credentials', 'Loaded Metrics credentials']);
        if (! $response instanceof MetricsCredentialsResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Metrics credentials.', [
            'URL' => $response->url,
            'Username' => $response->username,
            'Password' => $response->password,
            'Request ID' => $response->requestId,
        ]));

        return self::SUCCESS;
    }
}
