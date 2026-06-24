<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DatabaseConnections;

use App\Actions\DatabaseConnections\AddDatabaseUser;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseConnectionAddUserController extends DatabaseConnectionApiController
{
    public function __invoke(Request $request, string $connection): JsonResponse
    {
        $addDatabaseUser = app(AddDatabaseUser::class);
        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $service = $this->stringValue($request->input('service'));
        $database = $this->stringValue($request->input('database'));
        $username = $this->stringValue($request->input('username'));
        $password = $this->stringValue($request->input('password'));
        $nodeSelector = $this->stringValue($request->input('node'));

        $this->setActivityProperties($request, array_filter(
            [
                'slug' => $connection,
                'service' => $service,
                'database' => $database,
                'username' => $username,
                'node' => $nodeSelector,
            ],
            static fn (mixed $value): bool => $value !== null,
        ));

        foreach ([
            'service' => $service,
            'database' => $database,
            'username' => $username,
            'password' => $password,
        ] as $field => $value) {
            if ($value === null) {
                return $this->validationFailed($field, "The {$field} field is required.", ['field' => $field], 422);
            }
        }

        assert(is_string($service));
        assert(is_string($database));
        assert(is_string($username));
        assert(is_string($password));

        $node = null;

        if ($nodeSelector !== null) {
            $node = $this->resolver->resolveNode($nodeSelector);

            if (! $node instanceof Node) {
                return $this->validationFailed(
                    'node',
                    "Invalid value for --node: '{$nodeSelector}'.",
                    [
                        'field' => 'node',
                        'value' => $nodeSelector,
                    ],
                    422,
                );
            }
        }

        $process = $addDatabaseUser->resolveProcess($service, $node);

        if ($process instanceof DatabaseConnectionRegistryFailure) {
            return $this->failureResponse($process);
        }

        $authorization = $this->authorizeNodePermission($auth, $process->node, 'database:write');

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $result = $addDatabaseUser->handle($process, $connection, $database, $username, $password);

        return $this->connectionResponse($request, $result, 200);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /database-connections/{connection}/users';
    }
}
