<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

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
        $connections = is_array($data['connections'] ?? null)
            ? array_values(array_filter($data['connections'], is_array(...)))
            : [];
        $renderMeta = isset($meta['count']) ? ['count' => $meta['count']] : [];

        if ($this->wantsJson()) {
            return $this->renderSuccess(['connections' => $connections], $renderMeta);
        }

        if ($connections === []) {
            $this->line('No database connections matched this scope.');

            return self::SUCCESS;
        }

        $this->line('Showing '.count($connections).' database connection(s).');

        table(
            headers: ['SLUG', 'DRIVER', 'NODE', 'TARGETS'],
            rows: array_map(fn (array $connection): array => [
                $this->connectionString($connection, 'slug'),
                $this->connectionString($connection, 'driver'),
                $this->connectionString($connection, 'node'),
                $this->targetLabels($connection['targets'] ?? null),
            ], $connections),
        );

        return self::SUCCESS;
    }

    private function targetLabels(mixed $targets): string
    {
        if (! is_array($targets) || $targets === []) {
            return '—';
        }

        $labels = [];

        foreach ($targets as $target) {
            if (! is_array($target)) {
                continue;
            }

            $label = is_string($target['name'] ?? null) && $target['name'] !== ''
                ? $target['name']
                : $this->appInstanceLabel($target);

            if ($label !== null) {
                $labels[] = $label;
            }
        }

        return $labels === [] ? '—' : implode(', ', $labels);
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function appInstanceLabel(array $target): ?string
    {
        $app = is_string($target['app'] ?? null) && $target['app'] !== '' ? $target['app'] : null;
        $instance = is_string($target['instance'] ?? null) && $target['instance'] !== '' ? $target['instance'] : null;

        if ($app === null) {
            return null;
        }

        return $instance === null ? $app : "{$app} ({$instance})";
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function connectionString(array $connection, string $key): string
    {
        $value = $connection[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
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
