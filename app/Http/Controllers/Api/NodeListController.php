<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\NodesDoctorSummary;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

final readonly class NodeListController implements Loggable
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
            ->with('roleAssignments')
            ->orderBy('role')
            ->orderBy('name');

        if ($role !== null) {
            $query->where('role', $role);
        }

        if ($environment !== null) {
            $query->whereHas('roleAssignments', function ($query) use ($environment): void {
                $query
                    ->where('role', $environment === 'development' ? NodeRoleName::AppDevelopment->value : NodeRoleName::AppProduction->value)
                    ->where('status', NodeRoleStatus::Active->value);
            });
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
            'environment' => app(NodeRoleAssignments::class)->activeAppHostEnvironment($node),
            'platform' => $node->platform ?? 'unknown',
            'status' => $node->status,
            'roles' => $node->roleAssignments->map(fn (NodeRoleAssignment $assignment): array => [
                'role' => $assignment->role,
                'status' => $assignment->status,
                'settings' => $this->normalizeRoleSettings($assignment->settings),
            ])->all(),
        ])->all();
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function normalizeRoleSettings(mixed $settings): array|stdClass
    {
        if (! is_array($settings) || $settings === []) {
            return (object) [];
        }

        return $settings;
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:GET /nodes';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    public function properties(): array
    {
        return [];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
