<?php

declare(strict_types=1);

namespace App\Commands\Analytics;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class AnalyticsUpdateCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'analytics:update
        {--requested-version= : Plausible CE version to apply}
        {--node= : Active analytics node name}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Update the Plausible CE version used by analytics role nodes.';

    public function handle(): int
    {
        $version = $this->stringOption('requested-version');

        if ($version === null) {
            return $this->renderFailure('validation_failed', 'Plausible version is required.', [
                'field' => 'version',
            ]);
        }

        try {
            $response = $this->gatewayPost('/api/analytics/update', $this->filledQuery([
                'version' => $version,
                'node' => $this->stringOption('node'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderAnalyticsBlock($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderAnalyticsBlock(array $response): int
    {
        $data = $this->successData($response);
        $analytics = is_array($data['analytics'] ?? null) ? $data['analytics'] : [];

        $this->line('analytics:');
        $this->line('  node: '.$this->stringField($analytics, 'node'));

        $previousVersion = $analytics['previous_version'] ?? null;

        if (is_string($previousVersion) && $previousVersion !== '') {
            $this->line("  previous_version: {$previousVersion}");
        }

        $this->line('  version: '.$this->stringField($analytics, 'version'));
        $this->line('  process: '.$this->stringField($analytics, 'process'));
        $this->line('  status: '.$this->stringField($analytics, 'status'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function stringField(array $analytics, string $key): string
    {
        $value = $analytics[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $data = $response['success']['data'] ?? null;

        return is_array($data) ? $data : [];
    }
}
