<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class MetricsEnableCommand extends GatewayCommand
{
    use ResolvesHostContext;

    private const int GatewayTimeoutSeconds = 300;

    #[\Override]
    protected $signature = 'metrics:enable
        {--node= : Node to enable the metrics role on}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Enable the metrics role on a node.';

    public function handle(): int
    {
        $node = $this->stringOption('node');

        if ($node === null) {
            return $this->renderFailure('validation_failed', 'The node option is required.', ['field' => 'node']);
        }

        try {
            $response = $this->gateway()->withMinimumTimeout(self::GatewayTimeoutSeconds)->post('/api/nodes/'.rawurlencode($node).'/roles', [
                'role' => 'metrics',
                'settings' => [],
                'reconverge_existing' => true,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
