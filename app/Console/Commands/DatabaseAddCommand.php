<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithDatabaseRegistry;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\Requests\Database\AddDatabaseConnectionRequest;
use App\Services\DatabaseConnections\DatabaseConnectionPayloadMapper;
use App\Services\DatabaseConnections\DatabaseConnectionRegistry;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use App\Services\DatabaseConnections\DatabaseConnectionTargetResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('database:add
    {slug : Database connection slug}
    {--driver= : mysql|pgsql|sqlite}
    {--node= : Owning node selector}
    {--host= : Database host}
    {--port= : Database port}
    {--database= : Database name}
    {--path= : SQLite path}
    {--username= : Database username}
    {--password= : Database password}
    {--json : Output JSON}')]
#[Description('Create a database connection in the registry')]
final class DatabaseAddCommand extends Command
{
    use InteractsWithDatabaseRegistry;

    public function handle(DatabaseConnectionRegistry $registry, DatabaseConnectionPayloadMapper $payloads, DatabaseConnectionTargetResolver $resolver): int
    {
        $slug = (string) $this->argument('slug');
        $nodeSelector = $this->stringOption('node');
        $node = $this->isGatewayCaller() && $nodeSelector !== null
            ? $resolver->resolveNode($nodeSelector)
            : null;

        if ($this->isGatewayCaller() && $nodeSelector !== null && $node === null) {
            return $this->respondFailure('validation_failed', "Invalid value for --node: '{$nodeSelector}'.", [
                'field' => 'node',
                'value' => $nodeSelector,
            ]);
        }

        $payload = [
            'driver' => $this->stringOption('driver'),
            'host' => $this->stringOption('host'),
            'port' => $this->option('port'),
            'database' => $this->stringOption('database'),
            'path' => $this->stringOption('path'),
            'username' => $this->stringOption('username'),
            'password' => $this->stringOption('password'),
            'node_id' => $node?->id,
        ];

        if ($this->isGatewayCaller()) {
            $result = $registry->create($slug, $payload);

            if ($result instanceof DatabaseConnectionRegistryFailure) {
                return $this->respondFailure($result->code, $result->message, $result->meta);
            }

            $connection = $payloads->toArray($result);
        } else {
            $result = $this->sendGatewayRequest(new AddDatabaseConnectionRequest([
                'slug' => $slug,
                'driver' => $payload['driver'],
                'host' => $payload['host'],
                'port' => $payload['port'],
                'database' => $payload['database'],
                'path' => $payload['path'],
                'username' => $payload['username'],
                'password' => $payload['password'],
                'node' => $this->stringOption('node'),
            ]));

            if ($result instanceof GatewayApiException) {
                return $this->respondFailure($result->errorCode() ?? 'gateway_unavailable', $result->getMessage(), $result->errorMeta());
            }

            $connection = $result['connection'];
        }

        if ($this->wantsJson()) {
            return $this->respondSuccess(['connection' => $connection]);
        }

        $this->line("Database connection '{$connection['slug']}' created.");

        return self::SUCCESS;
    }
}
