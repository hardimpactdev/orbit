<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\RendersShowDetails;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class DatabaseShowCommand extends GatewayCommand
{
    use RendersShowDetails;
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'database:show
        {connection? : Database connection slug}
        {--json}';

    #[\Override]
    protected $description = 'Show one database connection from the registry.';

    public function handle(): int
    {
        $connection = $this->stringArgument('connection');

        if ($connection === null) {
            return $this->renderFailure('validation_failed', 'The connection argument is required.', ['field' => 'connection']);
        }

        try {
            $response = $this->gatewayGet('/api/database-connections/'.rawurlencode($connection));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderDatabaseShowSuccess($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderDatabaseShowSuccess(array $response): int
    {
        $data = $this->successData($response);
        $connectionPayload = is_array($data['connection'] ?? null) ? $data['connection'] : [];

        if ($this->wantsJson()) {
            return $this->renderSuccess(['connection' => $connectionPayload]);
        }

        $this->renderConnection($connectionPayload);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function renderConnection(array $connection): void
    {
        $slug = is_scalar($connection['slug'] ?? null) && (string) $connection['slug'] !== ''
            ? (string) $connection['slug']
            : 'unknown';

        $properties = [
            'Driver' => $this->scalarOrNull($connection, 'driver'),
            'Host' => $this->scalarOrNull($connection, 'host'),
            'Port' => $this->scalarOrNull($connection, 'port'),
            'Name' => $this->scalarOrNull($connection, 'database'),
            'User' => $this->scalarOrNull($connection, 'username'),
        ];

        if (($connection['driver'] ?? null) === 'sqlite' || $this->scalarOrNull($connection, 'path') !== null) {
            $properties['Path'] = $this->scalarOrNull($connection, 'path');
        }

        $properties['Apps'] = $this->targetLabels($connection['targets'] ?? null);
        $properties['Node'] = $this->scalarOrNull($connection, 'node');

        $this->renderShowDetails("Database connection: {$slug}", $properties);
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function scalarOrNull(array $connection, string $key): int|string|null
    {
        $value = $connection[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function targetLabels(mixed $targets): array
    {
        if (! is_array($targets)) {
            return [];
        }

        $labels = [];

        foreach ($targets as $target) {
            if (! is_array($target)) {
                continue;
            }

            if (is_string($target['name'] ?? null) && $target['name'] !== '') {
                $labels[] = $target['name'];

                continue;
            }

            $app = is_string($target['app'] ?? null) && $target['app'] !== '' ? $target['app'] : null;
            $instance = is_string($target['instance'] ?? null) && $target['instance'] !== '' ? $target['instance'] : null;

            if ($app !== null) {
                $labels[] = $instance === null ? $app : "{$app} ({$instance})";
            }
        }

        return $labels;
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
}
