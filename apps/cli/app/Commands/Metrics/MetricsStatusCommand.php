<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class MetricsStatusCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'metrics:status
        {--node= : Metrics node to inspect}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Show metrics role status.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/metrics/status', $this->filledQuery([
                'node' => $this->stringOption('node'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderStatusTable($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderStatusTable(array $response): int
    {
        $metrics = $this->metricsPayloads($response);

        table(
            ['Node', 'URL', 'Processes'],
            array_map(
                fn (array $metric): array => [
                    is_string($metric['node'] ?? null) ? $metric['node'] : '',
                    is_string($metric['url'] ?? null) ? $metric['url'] : '',
                    $this->processSummary($metric),
                ],
                $metrics,
            ),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function metricsPayloads(array $response): array
    {
        $data = is_array($response['success']['data'] ?? null) ? $response['success']['data'] : $response;
        $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];

        return array_values(array_filter($metrics, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $metric
     */
    private function processSummary(array $metric): string
    {
        $processes = is_array($metric['processes'] ?? null) ? $metric['processes'] : [];
        $names = [];

        foreach ($processes as $process) {
            if (is_array($process) && is_string($process['name'] ?? null)) {
                $names[] = $process['name'];
            }
        }

        return implode(', ', $names);
    }
}
