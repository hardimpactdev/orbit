<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use App\Services\Nodes\NodesDoctorSummary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class NodeListController
{
    private const array VALID_ROLES = ['gateway', 'app', 'control'];

    private const array VALID_ENVIRONMENTS = ['development', 'production'];

    public function __invoke(Request $request, NodesDoctorSummary $doctorSummary): JsonResponse
    {
        $role = $request->query('role');
        $environment = $request->query('environment');
        $doctor = (bool) filter_var($request->query('doctor', false), FILTER_VALIDATE_BOOLEAN);

        if (is_string($role) && $role !== '') {
            if (! in_array($role, self::VALID_ROLES, true)) {
                return response()->json([
                    'error' => [
                        'code' => 'validation_failed',
                        'message' => "Invalid value for role: '{$role}'. Allowed values: ".implode(', ', self::VALID_ROLES).'.',
                        'meta' => [
                            'field' => 'role',
                            'value' => $role,
                            'allowed' => self::VALID_ROLES,
                        ],
                    ],
                ], 400);
            }
        }

        if (is_string($environment) && $environment !== '') {
            if (! in_array($environment, self::VALID_ENVIRONMENTS, true)) {
                return response()->json([
                    'error' => [
                        'code' => 'validation_failed',
                        'message' => "Invalid value for environment: '{$environment}'. Allowed values: ".implode(', ', self::VALID_ENVIRONMENTS).'.',
                        'meta' => [
                            'field' => 'environment',
                            'value' => $environment,
                            'allowed' => self::VALID_ENVIRONMENTS,
                        ],
                    ],
                ], 400);
            }
        }

        $nodes = $this->fetchNodeModels(
            role: is_string($role) && $role !== '' ? $role : null,
            environment: is_string($environment) && $environment !== '' ? $environment : null,
        );

        $success = [
            'data' => [
                'nodes' => $this->nodePayloads($nodes),
            ],
        ];

        if ($doctor) {
            $success['meta'] = [
                'doctor' => $doctorSummary->forNodes($nodes),
            ];
        }

        return response()->json([
            'success' => $success,
        ]);
    }

    /**
     * @return Collection<int, Node>
     */
    private function fetchNodeModels(?string $role, ?string $environment): Collection
    {
        $query = Node::query()
            ->orderBy('role')
            ->orderBy('name');

        if ($role !== null) {
            $query->where('role', $role);
        }

        if ($environment !== null) {
            $query->where('environment', $environment);
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return list<array<string, mixed>>
     */
    private function nodePayloads(Collection $nodes): array
    {
        return $nodes->map(fn (Node $node): array => [
            'name' => $node->name,
            'role' => $node->role,
            'host' => $node->host,
            'environment' => $node->role === 'app' ? $node->environment : null,
            'platform' => $node->platform ?? 'unknown',
            'status' => $node->status,
        ])->all();
    }
}
