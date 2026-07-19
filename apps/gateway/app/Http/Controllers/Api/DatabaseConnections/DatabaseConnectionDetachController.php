<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DatabaseConnections;

use App\Enums\ActivityLogType;
use App\Models\AppInstance;
use App\Models\Workspace;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseConnectionDetachController extends DatabaseConnectionApiController
{
    public function __invoke(Request $request, string $connection): JsonResponse
    {
        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $envPrefix = $this->stringValue($request->input('env_prefix')) ?? 'DB';
        $scope = $this->resolveTargetScope($request, $envPrefix, $auth);

        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        [, $owner] = $scope;
        $existing = $this->registry->show($connection);

        if ($existing instanceof DatabaseConnectionRegistryFailure) {
            return $this->failureResponse($existing);
        }

        $authorization = $this->authorizeNodePermission($auth, $this->ownerNode($owner), 'database:write');

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        if ($owner instanceof AppInstance) {
            $targetType = 'app_instance';
            $targetName = $owner->app->name.'.'.$owner->name;
            $result = $this->registry->detachFromAppInstance($connection, $owner, $envPrefix);
        } elseif ($owner instanceof Workspace) {
            $targetType = 'workspace';
            $targetName = $owner->name;
            $result = $this->registry->detachFromWorkspace($connection, $owner, $envPrefix);
        }

        $this->setActivityProperties($request, [
            'slug' => $connection,
            'target_type' => $targetType,
            'target_name' => $targetName,
            'env_prefix' => $envPrefix,
        ]);

        if ($result instanceof DatabaseConnectionRegistryFailure) {
            return $this->failureResponse($result);
        }

        return response()->json([
            'success' => [
                'data' => [
                    'result' => [
                        'action' => 'detached',
                        'connection' => $connection,
                        'target_type' => $targetType,
                        'target' => $targetName,
                        'env_prefix' => $envPrefix,
                    ],
                ],
                'meta' => (object) [],
            ],
        ]);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:DELETE /database-connections/{connection}/targets';
    }
}
