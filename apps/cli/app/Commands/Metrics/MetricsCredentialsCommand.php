<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class MetricsCredentialsCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'metrics:credentials
        {--node= : Metrics node to read credentials for}
        {--reset : Rotate the Grafana admin password before returning credentials}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Show or reset Grafana credentials for the metrics role.';

    public function handle(): int
    {
        try {
            $response = (bool) $this->option('reset')
                ? $this->gatewayPost('/api/metrics/credentials/reset', $this->filledQuery([
                    'node' => $this->stringOption('node'),
                ]))
                : $this->gatewayGet('/api/metrics/credentials', $this->filledQuery([
                    'node' => $this->stringOption('node'),
                ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderCredentialsTable($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderCredentialsTable(array $response): int
    {
        $data = is_array($response['success']['data'] ?? null) ? $response['success']['data'] : $response;
        $credentials = is_array($data['credentials'] ?? null) ? $data['credentials'] : [];

        table(
            ['Field', 'Value'],
            [
                ['Node', is_string($credentials['node'] ?? null) ? $credentials['node'] : ''],
                ['URL', is_string($credentials['url'] ?? null) ? $credentials['url'] : ''],
                ['Admin User', is_string($credentials['admin_user'] ?? null) ? $credentials['admin_user'] : ''],
                ['Admin Password', is_string($credentials['admin_password'] ?? null) ? $credentials['admin_password'] : ''],
            ],
        );

        return self::SUCCESS;
    }
}
