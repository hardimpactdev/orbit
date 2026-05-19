<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithDatabaseRegistry;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\Requests\Database\ListDatabaseConnectionsRequest;
use App\Models\DatabaseConnection;
use App\Services\DatabaseConnections\DatabaseAuditPayload;
use App\Services\DatabaseConnections\DatabaseConnectionPayloadMapper;
use App\Services\DatabaseConnections\DatabaseConnectionRegistry;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('database:list
    {--app= : Filter by app selector}
    {--workspace= : Filter by workspace selector}
    {--node= : Filter by node selector}
    {--json : Output JSON}')]
#[Description('List database connections tracked by the registry')]
final class DatabaseListCommand extends Command implements Loggable
{
    use InteractsWithDatabaseRegistry;

    public function handle(DatabaseConnectionRegistry $registry, DatabaseConnectionPayloadMapper $payloads, DatabaseAuditPayload $audit): int
    {
        return $this->withDatabaseActivity(ActivityLogType::Read, fn (): int => $this->runDatabaseList($registry, $payloads, $audit));
    }

    private function runDatabaseList(DatabaseConnectionRegistry $registry, DatabaseConnectionPayloadMapper $payloads, DatabaseAuditPayload $audit): int
    {
        $this->databaseActivityProperties($audit->registry('list', extra: array_filter([
            'app' => $this->stringOption('app'),
            'workspace' => $this->stringOption('workspace'),
            'node' => $this->stringOption('node'),
        ], static fn (mixed $value): bool => $value !== null)));

        if ($this->stringOption('app') !== null && $this->stringOption('workspace') !== null) {
            return $this->respondFailure(
                'validation_failed',
                'Invalid scope: --app and --workspace cannot be combined.',
                ['field' => 'scope'],
            );
        }

        if ($this->isGatewayCaller()) {
            $filters = $this->resolveListFilters();

            if ($filters instanceof DatabaseConnectionRegistryFailure) {
                return $this->respondFailure($filters->code, $filters->message, $filters->meta);
            }

            [$app, $workspace, $node] = $filters;
            $connections = $registry->list($app, $workspace, $node)
                ->map(fn (DatabaseConnection $connection): array => $payloads->toArray($connection))
                ->all();
            $this->databaseActivityProperties($audit->registry('list', extra: array_filter([
                'app' => $this->stringOption('app'),
                'workspace' => $this->stringOption('workspace'),
                'node' => $this->stringOption('node'),
                'count' => count($connections),
            ], static fn (mixed $value): bool => $value !== null)));
        } else {
            $result = $this->sendGatewayRequest(new ListDatabaseConnectionsRequest(
                app: $this->stringOption('app'),
                workspace: $this->stringOption('workspace'),
                node: $this->stringOption('node'),
            ));

            if ($result instanceof GatewayApiException) {
                return $this->respondFailure(
                    $result->errorCode() ?? 'gateway_unavailable',
                    $result->getMessage(),
                    $result->errorMeta(),
                );
            }

            $connections = $result['connections'];
        }

        if ($this->wantsJson()) {
            return $this->respondSuccess(['connections' => $connections], ['count' => count($connections)]);
        }

        if ($connections === []) {
            $this->line('No database connections matched this scope.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Showing %d database connection(s).', count($connections)));
        $this->table(['slug', 'driver', 'node', 'targets'], array_map(
            fn (array $connection): array => [
                $connection['slug'],
                $connection['driver'],
                $connection['node'] ?? '-',
                (string) count($connection['targets'] ?? []),
            ],
            $connections,
        ));

        return self::SUCCESS;
    }
}
