<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Api\AddNodeRoleApiRequest;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

#[RequiresPermission('role:add', servingNode: ServingNode::Target)]
final class NodeRoleAddController implements Loggable
{
    private ?Node $activitySubject = null;

    public function __construct(private readonly NodeRoleAssignmentService $service) {}

    public function __invoke(AddNodeRoleApiRequest $request, string $name): JsonResponse
    {
        if ($request->role() === 'gateway') {
            return $this->error(
                'validation_failed',
                'The gateway role cannot be managed through node role commands.',
                ['field' => 'role', 'role' => 'gateway'],
                422,
            );
        }

        $node = Node::query()->where('name', $name)->where('status', 'active')->first();
        if (! $node instanceof Node) {
            return $this->error('node.not_found', "Node '{$name}' not found.", ['name' => $name], 404);
        }

        $this->activitySubject = $node;

        try {
            $assignment = $this->service->add($node, $request->role(), $request->settings());
        } catch (InvalidArgumentException $exception) {
            return $this->error('validation_failed', $exception->getMessage(), ['role' => $request->role()], 422);
        }

        return response()->json([
            'success' => [
                'data' => [
                    'node' => $node->name,
                    'assignment' => NodeRoleAssignmentPayload::fromModel($assignment),
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'node.role.added';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    public function properties(): array
    {
        return [
            'node' => (string) request()->route('name'),
            'role' => (string) request('role'),
        ];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        $role = (string) request('role');
        $name = (string) request()->route('name');

        return $role !== '' && $name !== '' ? "Role {$role} added to {$name}" : null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
