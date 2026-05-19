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
        $scope = $this->isGatewayCaller()
            ? $this->resolveTargetScope()
            : $this->resolveTargetScopeForForwarding();

        if ($scope instanceof DatabaseConnectionRegistryFailure) {
            return $this->respondFailure($scope->code, $scope->message, $scope->meta);
        }

        if ($this->isGatewayCaller()) {
            [$type, $owner, $envPrefix] = $scope;
            $result = $type === 'app'
                ? $registry->detachFromApp($slug, $owner, $envPrefix)
                : $registry->detachFromWorkspace($slug, $owner, $envPrefix);

            if ($result instanceof DatabaseConnectionRegistryFailure) {
                return $this->respondFailure($result->code, $result->message, $result->meta);
            }
        } else {
            [$type, $target, $envPrefix] = $scope;
            $result = $this->sendGatewayRequest(new DetachDatabaseConnectionTargetRequest($slug, array_filter([
                'app' => $type === 'app' ? $target : null,
                'workspace' => $type === 'workspace' ? $target : null,
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
                    'target' => $this->isGatewayCaller() ? $owner->name : $target,
                    'env_prefix' => $envPrefix,
                ],
            ]);
        }

        $targetLabel = $this->isGatewayCaller() ? $owner->name : $target;
        $this->line("Detached database connection '{$slug}' from {$type} '{$targetLabel}' prefix '{$envPrefix}'.");

        return self::SUCCESS;
    }
}
