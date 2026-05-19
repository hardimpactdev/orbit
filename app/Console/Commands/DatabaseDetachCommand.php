<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithDatabaseRegistry;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\Requests\Database\DetachDatabaseConnectionTargetRequest;
use App\Services\DatabaseConnections\DatabaseConnectionRegistry;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('database:detach
    {connection : Database connection slug}
    {--app= : App selector}
    {--workspace= : Workspace selector}
    {--env-prefix=DB : Target env prefix}
    {--json : Output JSON}')]
#[Description('Detach a database connection from an app or workspace')]
final class DatabaseDetachCommand extends Command
{
    use InteractsWithDatabaseRegistry;

    public function handle(DatabaseConnectionRegistry $registry): int
    {
        $slug = (string) $this->argument('connection');
        $scope = $this->resolveTargetScope();

        if ($scope instanceof DatabaseConnectionRegistryFailure) {
            return $this->respondFailure($scope->code, $scope->message, $scope->meta);
        }

        [$type, $owner, $envPrefix] = $scope;

        if ($this->isGatewayCaller()) {
            $result = $type === 'app'
                ? $registry->detachFromApp($slug, $owner, $envPrefix)
                : $registry->detachFromWorkspace($slug, $owner, $envPrefix);

            if ($result instanceof DatabaseConnectionRegistryFailure) {
                return $this->respondFailure($result->code, $result->message, $result->meta);
            }
        } else {
            $result = $this->sendGatewayRequest(new DetachDatabaseConnectionTargetRequest($slug, array_filter([
                'app' => $type === 'app' ? $owner->name : null,
                'workspace' => $type === 'workspace' ? $owner->name : null,
                'env_prefix' => $envPrefix,
            ], static fn (mixed $value): bool => $value !== null)));

            if ($result instanceof GatewayApiException) {
                return $this->respondFailure($result->errorCode() ?? 'gateway_unavailable', $result->getMessage(), $result->errorMeta());
            }
        }

        if ($this->wantsJson()) {
            return $this->respondSuccess([
                'result' => [
                    'action' => 'detached',
                    'connection' => $slug,
                    'target_type' => $type,
                    'target' => $owner->name,
                    'env_prefix' => $envPrefix,
                ],
            ]);
        }

        $this->line("Detached database connection '{$slug}' from {$type} '{$owner->name}' prefix '{$envPrefix}'.");

        return self::SUCCESS;
    }
}
