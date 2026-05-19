<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithDatabaseRegistry;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\Requests\Database\RemoveDatabaseConnectionRequest;
use App\Services\DatabaseConnections\DatabaseConnectionRegistry;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('database:remove
    {connection : Database connection slug}
    {--force : Confirm destructive removal}
    {--json : Output JSON}')]
#[Description('Remove a database connection from the registry')]
final class DatabaseRemoveCommand extends Command
{
    use InteractsWithDatabaseRegistry;

    public function handle(DatabaseConnectionRegistry $registry): int
    {
        $slug = (string) $this->argument('connection');

        if (! $this->option('force')) {
            return $this->respondFailure(
                'validation_failed',
                'Use --force to remove this database connection.',
                ['field' => 'force', 'reason' => 'destructive_consent_required'],
            );
        }

        if ($this->isGatewayCaller()) {
            $result = $registry->remove($slug, true);

            if ($result instanceof DatabaseConnectionRegistryFailure) {
                return $this->respondFailure($result->code, $result->message, $result->meta);
            }
        } else {
            $result = $this->sendGatewayRequest(new RemoveDatabaseConnectionRequest($slug));

            if ($result instanceof GatewayApiException) {
                return $this->respondFailure($result->errorCode() ?? 'gateway_unavailable', $result->getMessage(), $result->errorMeta());
            }
        }

        if ($this->wantsJson()) {
            return $this->respondSuccess([
                'result' => [
                    'action' => 'removed',
                    'connection' => $slug,
                ],
            ]);
        }

        $this->line("Database connection '{$slug}' removed.");

        return self::SUCCESS;
    }
}
