<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithDatabaseRegistry;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\Requests\Database\AttachDatabaseConnectionTargetRequest;
use App\Services\DatabaseConnections\DatabaseConnectionPayloadMapper;
use App\Services\DatabaseConnections\DatabaseConnectionRegistry;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('database:attach
    {connection : Database connection slug}
    {--app= : App selector}
    {--workspace= : Workspace selector}
    {--env-prefix=DB : Target env prefix}
    {--json : Output JSON}')]
#[Description('Attach a database connection to an app or workspace')]
final class DatabaseAttachCommand extends Command
{
    use InteractsWithDatabaseRegistry;

    public function handle(DatabaseConnectionRegistry $registry, DatabaseConnectionPayloadMapper $payloads): int
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
                ? $registry->attachToApp($slug, $owner, $envPrefix)
                : $registry->attachToWorkspace($slug, $owner, $envPrefix);

            if ($result instanceof DatabaseConnectionRegistryFailure) {
                return $this->respondFailure($result->code, $result->message, $result->meta);
            }

            $connection = $payloads->toArray($registry->show($slug));
        } else {
            [$type, $target, $envPrefix] = $scope;
            $result = $this->sendGatewayRequest(new AttachDatabaseConnectionTargetRequest($slug, array_filter([
                'app' => $type === 'app' ? $target : null,
                'workspace' => $type === 'workspace' ? $target : null,
                'env_prefix' => $envPrefix,
            ], static fn (mixed $value): bool => $value !== null)));

            if ($result instanceof GatewayApiException) {
                return $this->respondFailure($result->errorCode() ?? 'gateway_unavailable', $result->getMessage(), $result->errorMeta());
            }

            $connection = $result['connection'];
        }

        if ($this->wantsJson()) {
            return $this->respondSuccess(['connection' => $connection]);
        }

        $targetLabel = $this->isGatewayCaller() ? $owner->name : $target;
        $this->line("Attached database connection '{$slug}' to {$type} '{$targetLabel}' with prefix '{$envPrefix}'.");

        return self::SUCCESS;
    }
}
