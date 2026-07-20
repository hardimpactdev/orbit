<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DatabaseConnections;

use App\Enums\ActivityLogType;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\DatabaseConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseConnectionIndexController extends DatabaseConnectionApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $instance = $this->stringValue($request->query('instance'));
        $workspace = $this->stringValue($request->query('workspace'));
        $node = $this->stringValue($request->query('node'));
        $this->setActivityProperties($request, array_filter(
            compact('instance', 'workspace', 'node'),
            static fn (mixed $value): bool => $value !== null,
        ));

        $instanceModel = $instance !== null ? $this->resolver->resolveAppInstanceSelector($instance) : null;

        try {
            $workspaceModel = $workspace !== null
                ? $this->resolver->resolveWorkspaceForCaller($workspace, $auth)
                : null;
        } catch (WorkspaceUnsupportedForProduction $exception) {
            return $this->workspaceUnsupportedForProduction($exception);
        }

        if ($instance !== null && $workspace !== null) {
            return $this->validationFailed('scope', 'Invalid scope: --instance and --workspace cannot be combined.', [
                'field' => 'scope',
            ]);
        }

        $nodeModel = $node !== null ? $this->resolver->resolveNode($node) : null;

        if ($instance !== null && $instanceModel === null) {
            return $this->validationFailed('instance', "Invalid value for --instance: '{$instance}'.", [
                'field' => 'instance',
                'value' => $instance,
            ]);
        }

        if ($workspace !== null && $workspaceModel === null) {
            return $this->validationFailed('workspace', "Invalid value for --workspace: '{$workspace}'.", [
                'field' => 'workspace',
                'value' => $workspace,
            ]);
        }

        if ($node !== null && $nodeModel === null) {
            return $this->validationFailed('node', "Invalid value for --node: '{$node}'.", [
                'field' => 'node',
                'value' => $node,
            ]);
        }

        $authorization = $this->authorizeListScope($auth, $instanceModel, $workspaceModel, $nodeModel);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $connections = $this->registry->list(instance: $instanceModel, workspace: $workspaceModel, node: $nodeModel);

        if (
            ! $this->roles->nodeIsGateway($auth)
            && $instanceModel === null
            && $workspaceModel === null
            && $nodeModel === null
        ) {
            $connections = $connections
                ->filter(
                    fn (DatabaseConnection $connection): bool => $this->connectionAllowsAny(
                        $auth,
                        $connection,
                        'database:read',
                    ),
                )
                ->values();
        }

        $connections = $connections
            ->map(fn (DatabaseConnection $connection): array => $this->payloads->toArray($connection, $auth))
            ->all();

        return response()->json([
            'success' => [
                'data' => ['connections' => $connections],
                'meta' => ['count' => count($connections)],
            ],
        ]);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /database-connections';
    }
}
