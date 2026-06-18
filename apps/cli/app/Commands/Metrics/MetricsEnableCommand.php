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

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderEnabled($node, $response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderEnabled(string $node, array $response): int
    {
        $data = $this->successData($response);
        $assignment = is_array($data['assignment'] ?? null) ? $data['assignment'] : [];
        $targetNode = is_string($data['node'] ?? null) && $data['node'] !== '' ? $data['node'] : $node;
        $role = is_string($assignment['role'] ?? null) && $assignment['role'] !== '' ? $assignment['role'] : 'metrics';
        $status = is_string($assignment['status'] ?? null) && $assignment['status'] !== '' ? $assignment['status'] : 'unknown';

        $this->line("Metrics role enabled on '{$targetNode}'");
        $this->line("Role: {$role}");
        $this->line("Status: {$status}");

        return self::SUCCESS;
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
