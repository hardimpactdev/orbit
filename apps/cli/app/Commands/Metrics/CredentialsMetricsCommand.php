<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Metrics\ResetMetricsCredentialsRequest;
use Orbit\Sdk\Requests\Metrics\ShowMetricsCredentialsRequest;
use Orbit\Sdk\Responses\Metrics\MetricsCredentialsResponse;

final class CredentialsMetricsCommand extends MetricsCommand
{
    #[\Override]
    protected $signature = 'metrics:credentials {--reset : Reset the password} {--idempotency-key= : Reuse a key to retry the credential reset} {--json : Return machine-readable JSON}';

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
            return $this->integrationOperation($connector, $repository, new ResetMetricsCredentialsRequest(is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null), 'Reset Metrics credentials');
        }
        $response = $this->sendWithProgress($connector, new ShowMetricsCredentialsRequest, MetricsCredentialsResponse::class, ['Show Metrics credentials', 'Loading Metrics credentials', 'Loaded Metrics credentials']);
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
