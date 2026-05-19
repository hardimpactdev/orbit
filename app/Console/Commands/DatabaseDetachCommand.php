<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithDatabaseRegistry;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\Requests\Database\DetachDatabaseConnectionTargetRequest;
use App\Models\DatabaseConnection;
use App\Services\DatabaseConnections\DatabaseAuditPayload;
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
final class DatabaseDetachCommand extends Command implements Loggable
{
    use InteractsWithDatabaseRegistry;

    public function handle(DatabaseConnectionRegistry $registry, DatabaseAuditPayload $audit): int
    {
        return $this->withDatabaseActivity(ActivityLogType::Write, fn (): int => $this->runDatabaseDetach($registry, $audit));
    }

    private function runDatabaseDetach(DatabaseConnectionRegistry $registry, DatabaseAuditPayload $audit): int
    {
        $slug = (string) $this->argument('connection');
        $this->databaseActivityProperties($audit->registry('detach', extra: [
            'connection' => $slug,
            'app' => $this->stringOption('app'),
            'workspace' => $this->stringOption('workspace'),
            'env_prefix' => $this->stringOption('env-prefix') ?? 'DB',
        ]));

        $scope = $this->isGatewayCaller()
            ? $this->resolveTargetScope()
            : $this->resolveTargetScopeForForwarding();

        if ($scope instanceof DatabaseConnectionRegistryFailure) {
            return $this->respondFailure($scope->code, $scope->message, $scope->meta);
        }

        $targetLabel = '';

        if ($this->isGatewayCaller()) {
            [$type, $owner, $envPrefix] = $scope;
            $targetLabel = $owner->name;
            $connectionModel = $registry->show($slug);

            if ($connectionModel instanceof DatabaseConnection) {
                $this->databaseActivitySubject($connectionModel);
                $this->databaseActivityProperties($audit->registry('detach', $connectionModel, [
                    'target_type' => $type,
                    'target_name' => $owner->name,
                    'env_prefix' => $envPrefix,
                ]));
            }

            $result = $type === 'app'
                ? $registry->detachFromApp($slug, $owner, $envPrefix)
                : $registry->detachFromWorkspace($slug, $owner, $envPrefix);

            if ($result instanceof DatabaseConnectionRegistryFailure) {
                return $this->respondFailure($result->code, $result->message, $result->meta);
            }
        } else {
            [$type, $target, $envPrefix] = $scope;
            $targetLabel = $target;
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
                    'target' => $targetLabel,
                    'env_prefix' => $envPrefix,
                ],
            ]);
        }

        $this->line("Detached database connection '{$slug}' from {$type} '{$targetLabel}' prefix '{$envPrefix}'.");

        return self::SUCCESS;
    }
}
