<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithDatabaseRegistry;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\Requests\Database\UpdateDatabaseConnectionRequest;
use App\Services\DatabaseConnections\DatabaseConnectionPayloadMapper;
use App\Services\DatabaseConnections\DatabaseConnectionRegistry;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use App\Services\DatabaseConnections\DatabaseConnectionTargetResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('database:update
    {connection : Database connection slug}
    {--node= : Owning node selector}
    {--slug= : New slug}
    {--driver= : mysql|pgsql|sqlite}
    {--host= : Database host}
    {--port= : Database port}
    {--database= : Database name}
    {--path= : SQLite path}
    {--username= : Database username}
    {--password= : Database password}
    {--clear-password : Clear the stored password}
    {--json : Output JSON}')]
#[Description('Update a database connection in the registry')]
final class DatabaseUpdateCommand extends Command
{
    use InteractsWithDatabaseRegistry;

    public function handle(DatabaseConnectionRegistry $registry, DatabaseConnectionPayloadMapper $payloads, DatabaseConnectionTargetResolver $resolver): int
    {
        $slug = (string) $this->argument('connection');
        $payload = array_filter([
            'slug' => $this->stringOption('slug'),
            'driver' => $this->stringOption('driver'),
            'host' => $this->stringOption('host'),
            'port' => $this->option('port'),
            'database' => $this->stringOption('database'),
            'path' => $this->stringOption('path'),
            'username' => $this->stringOption('username'),
            'password' => $this->stringOption('password'),
            'node_id' => $this->option('node') !== null ? $resolver->resolveNode($this->stringOption('node'))?->id : null,
            'clear_password' => $this->option('clear-password') ? true : null,
        ], static fn (mixed $value): bool => $value !== null);

        if ($payload === []) {
            return $this->respondFailure('validation_failed', 'At least one mutable field is required.', ['field' => 'payload']);
        }

        if ($this->isGatewayCaller()) {
            $result = $registry->update($slug, $payload);

            if ($result instanceof DatabaseConnectionRegistryFailure) {
                return $this->respondFailure($result->code, $result->message, $result->meta);
            }

            $connection = $payloads->toArray($result);
        } else {
            $result = $this->sendGatewayRequest(new UpdateDatabaseConnectionRequest($slug, array_filter([
                'slug' => $this->stringOption('slug'),
                'driver' => $this->stringOption('driver'),
                'host' => $this->stringOption('host'),
                'port' => $this->option('port'),
                'database' => $this->stringOption('database'),
                'path' => $this->stringOption('path'),
                'username' => $this->stringOption('username'),
                'password' => $this->stringOption('password'),
                'node' => $this->stringOption('node'),
                'clear_password' => $this->option('clear-password') ? true : null,
            ], static fn (mixed $value): bool => $value !== null)));

            if ($result instanceof GatewayApiException) {
                return $this->respondFailure($result->errorCode() ?? 'gateway_unavailable', $result->getMessage(), $result->errorMeta());
            }

            $connection = $result['connection'];
        }

        if ($this->wantsJson()) {
            return $this->respondSuccess(['connection' => $connection]);
        }

        $this->line("Database connection '{$connection['slug']}' updated.");

        return self::SUCCESS;
    }
}
