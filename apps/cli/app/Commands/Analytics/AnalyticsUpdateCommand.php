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

        return $this->renderSuccess($response);
    }
}
