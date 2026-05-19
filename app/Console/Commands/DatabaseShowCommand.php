<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithDatabaseRegistry;
use App\Console\Commands\Concerns\RendersShowDetails;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\Requests\Database\ShowDatabaseConnectionRequest;
use App\Services\DatabaseConnections\DatabaseAuditPayload;
use App\Services\DatabaseConnections\DatabaseConnectionPayloadMapper;
use App\Services\DatabaseConnections\DatabaseConnectionRegistry;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('database:show
    {connection : Database connection slug}
    {--json : Output JSON}')]
#[Description('Show one database connection from the registry')]
final class DatabaseShowCommand extends Command implements Loggable
{
    use InteractsWithDatabaseRegistry;
    use RendersShowDetails;

    public function handle(DatabaseConnectionRegistry $registry, DatabaseConnectionPayloadMapper $payloads, DatabaseAuditPayload $audit): int
    {
        return $this->withDatabaseActivity(ActivityLogType::Read, fn (): int => $this->runDatabaseShow($registry, $payloads, $audit));
    }

    private function runDatabaseShow(DatabaseConnectionRegistry $registry, DatabaseConnectionPayloadMapper $payloads, DatabaseAuditPayload $audit): int
    {
        $slug = (string) $this->argument('connection');
        $this->databaseActivityProperties($audit->registry('show', extra: ['connection' => $slug]));

        if ($this->isGatewayCaller()) {
            $result = $registry->show($slug);

            if ($result instanceof DatabaseConnectionRegistryFailure) {
                return $this->respondFailure($result->code, $result->message, $result->meta);
            }

            $this->databaseActivitySubject($result);
            $this->databaseActivityProperties($audit->registry('show', $result));
            $connection = $payloads->toArray($result);
        } else {
            $result = $this->sendGatewayRequest(new ShowDatabaseConnectionRequest($slug));

            if ($result instanceof GatewayApiException) {
                return $this->respondFailure(
                    $result->errorCode() ?? 'gateway_unavailable',
                    $result->getMessage(),
                    $result->errorMeta(),
                );
            }

            $connection = $result['connection'];
        }

        if ($this->wantsJson()) {
            return $this->respondSuccess(['connection' => $connection]);
        }

        $properties = [
            'Driver' => $connection['driver'] ?? null,
            'Host' => $connection['host'] ?? null,
            'Port' => $connection['port'] ?? null,
            'Name' => $connection['database'] ?? null,
        ];

        if (($connection['path'] ?? null) !== null && $connection['path'] !== '') {
            $properties['Path'] = $connection['path'];
        }

        $this->renderShowDetails("Database connection: {$connection['slug']}", [
            ...$properties,
            'User' => $connection['username'] ?? null,
            'Apps' => $this->targetLabels($connection['targets'] ?? []),
            'Node' => $connection['node'] ?? null,
        ]);

        return self::SUCCESS;
    }

    private function targetLabels(mixed $targets): string
    {
        if (! is_array($targets)) {
            return '—';
        }

        $labels = [];

        foreach ($targets as $target) {
            if (! is_array($target) || ! is_string($target['name'] ?? null) || $target['name'] === '') {
                continue;
            }

            $labels[] = $target['name'];
        }

        $labels = array_values(array_unique($labels));

        return $labels === [] ? '—' : implode(', ', $labels);
    }
}
