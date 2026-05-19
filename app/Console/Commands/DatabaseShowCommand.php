<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithDatabaseRegistry;
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

        $this->line("Showing database connection '{$slug}'.");
        $this->table(['field', 'value'], [
            ['slug', $connection['slug']],
            ['driver', $connection['driver']],
            ['host', $connection['host'] ?? '-'],
            ['port', $connection['port'] ?? '-'],
            ['database', $connection['database'] ?? '-'],
            ['path', $connection['path'] ?? '-'],
            ['username', $connection['username'] ?? '-'],
            ['node', $connection['node'] ?? '-'],
        ]);

        return self::SUCCESS;
    }
}
