<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class DatabaseListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'database:list
        {--app= : Filter by app selector}
        {--workspace= : Filter by workspace selector}
        {--node= : Filter by node selector}
        {--json}';

    #[\Override]
    protected $description = 'List database connections tracked by the registry.';

    public function handle(): int
    {
        if ($this->hasMutuallyExclusiveOptions('app', 'workspace')) {
            return $this->renderFailure(
                'validation_failed',
                'Invalid scope: --app and --workspace cannot be combined.',
                ['field' => 'scope'],
            );
        }

        try {
            $response = $this->gatewayGet('/api/database-connections', $this->filledQuery([
                'app' => $this->stringOption('app'),
                'workspace' => $this->stringOption('workspace'),
                'node' => $this->stringOption('node'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderDatabaseListSuccess($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderDatabaseListSuccess(array $response): int
    {
        $data = $this->successData($response);
        $meta = $this->successMeta($response);
        $connections = is_array($data['connections'] ?? null) ? $data['connections'] : [];
        $renderMeta = isset($meta['count']) ? ['count' => $meta['count']] : [];

        return $this->renderSuccess(['connections' => $connections], $renderMeta);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success) && is_array($success['data'] ?? null)) {
            return $success['data'];
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successMeta(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success) && is_array($success['meta'] ?? null)) {
            return $success['meta'];
        }

        return [];
    }
}
