<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\DatabaseServers\CloseDatabaseServerAction;
use App\Actions\DatabaseServers\CreateDatabaseServerAction;
use App\Actions\DatabaseServers\DestroyDatabaseServerAction;
use App\Actions\DatabaseServers\ListDatabaseServersAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Data\DatabaseServers\DatabaseServerData;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\DatabaseServers\StoreDatabaseServerRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class DatabaseServersController extends Controller
{
    public function index(Request $request, ListDatabaseServersAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action
                ->execute()
                ->map(static fn (DatabaseServer $server): array => DatabaseServerData::fromModel($server)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[StartsOperation('database_server.create')]
    public function store(StoreDatabaseServerRequest $request, CreateDatabaseServerAction $action): JsonResponse
    {
        return response()->json(['data' => OperationData::fromModel($action->execute($request->payload()))->toArray(), 'meta' => $this->meta($request)], 202);
    }

    public function show(Request $request, DatabaseServer $databaseServer): JsonResponse
    {
        return response()->json([
            'data' => [
                ...DatabaseServerData::fromModel($databaseServer)->toArray(),
                'databases' => $databaseServer->databaseConnections()
                    ->with('server')
                    ->orderBy('slug')
                    ->get()
                    ->map(static fn (DatabaseConnection $connection): array => DatabaseConnectionData::fromModel($connection)->toArray())
                    ->values()
                    ->all(),
            ],
            'meta' => $this->meta($request),
        ]);
    }

    #[StartsOperation('database_server.close')]
    public function close(Request $request, string $databaseServer, CloseDatabaseServerAction $action): JsonResponse
    {
        $server = DatabaseServer::query()->where('slug', $databaseServer)->firstOrFail();

        return response()->json(['data' => OperationData::fromModel($action->execute($server))->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[StartsOperation('database_server.destroy')]
    public function destroy(Request $request, string $databaseServer, DestroyDatabaseServerAction $action): JsonResponse
    {
        $server = DatabaseServer::query()->where('slug', $databaseServer)->firstOrFail();

        return response()->json(['data' => OperationData::fromModel($action->execute($server))->toArray(), 'meta' => $this->meta($request)], 202);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
