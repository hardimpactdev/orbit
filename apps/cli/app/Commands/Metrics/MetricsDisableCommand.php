<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;

final class MetricsDisableCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'metrics:disable
        {--node= : Node to disable the metrics role on}
        {--force : Confirm metrics role removal}
        {--purge-data : Purge metrics-owned data}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Disable the metrics role on a node.';

    public function handle(): int
    {
        $node = $this->stringOption('node');

        if ($node === null) {
            return $this->renderFailure('validation_failed', 'The node option is required.', ['field' => 'node']);
        }

        $confirmation = $this->confirmDisable($node);

        if ($confirmation !== null) {
            return $confirmation;
        }

        try {
            $response = $this->gatewayDelete('/api/nodes/'.rawurlencode($node).'/roles/metrics', [
                'force' => true,
                'purge_data' => (bool) $this->option('purge-data'),
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function confirmDisable(string $node): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure(
                'validation_failed',
                'Use --force to disable metrics.',
                ['field' => 'force', 'reason' => 'destructive_consent_required'],
            );
        }

        if (confirm(label: "Disable metrics on '{$node}'?", default: false)) {
            return null;
        }

        return $this->renderFailure(
            'validation_failed',
            'Operation cancelled.',
            ['field' => 'force', 'reason' => 'destructive_consent_required'],
        );
    }
}
