<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Api\Concerns\ResolvesVisibleToolNodes;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolPayloadMapper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ToolShowController implements Loggable
{
    use ResolvesVisibleToolNodes;

    private ?NodeTool $activitySubject = null;

    public function __invoke(Request $request, string $tool): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $catalog = app(ToolCatalog::class);

        if (! $catalog->supports($tool)) {
            return response()->json([
                'error' => [
                    'code' => 'tool.unsupported_action',
                    'message' => "Tool '{$tool}' is not in the supported tool catalog.",
                    'meta' => [
                        'tool' => $tool,
                        'supported' => $catalog->names(),
                    ],
                ],
            ], 400);
        }

        $visibleNodeIds = $this->visibleToolNodeIds($caller);

        if ($caller->role !== 'gateway' && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to read the tool registry.');
        }

        $targetNode = $this->resolveTargetNode($request, $caller, $visibleNodeIds);

        if ($targetNode instanceof JsonResponse) {
            return $targetNode;
        }

        $model = NodeTool::query()
            ->with('node')
            ->where('node_id', $targetNode->id)
            ->where('name', $tool)
            ->first();

        if (! $model instanceof NodeTool) {
            return response()->json([
                'error' => [
                    'code' => 'tool.not_found',
                    'message' => "Tool '{$tool}' not found on node '{$targetNode->name}'.",
                    'meta' => [
                        'tool' => $tool,
                        'node' => $targetNode->name,
                    ],
                ],
            ], 404);
        }

        $this->activitySubject = $model;

        return response()->json([
            'success' => [
                'data' => [
                    'tool' => $this->toolPayload($model),
                ],
            ],
        ]);
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function resolveTargetNode(Request $request, Node $caller, array $visibleNodeIds): Node|JsonResponse
    {
        $node = $request->query('node');
        $app = $request->query('app');

        if (is_string($node) && $node !== '') {
            $nodeFilter = $this->resolveNodeFilter($node, $caller, $visibleNodeIds);

            if (! $nodeFilter instanceof Node) {
                return $this->validationFailed('node', $node, "Invalid value for --node: '{$node}'. Expected a visible app node name.");
            }
        }

        if (is_string($app) && $app !== '') {
            $appNode = $this->resolveAppNodeFilter($app, $caller, $visibleNodeIds);

            if (! $appNode instanceof Node) {
                return $this->validationFailed('app', $app, "Invalid value for --app: '{$app}'. Expected a visible app name or domain.");
            }

            if (isset($nodeFilter) && $nodeFilter->id !== $appNode->id) {
                return $this->validationFailed('app', $app, "Invalid value for --app: '{$app}'. App is not owned by the selected node.");
            }

            return $appNode;
        }

        if (isset($nodeFilter)) {
            return $nodeFilter;
        }

        $nodes = Node::query()
            ->where('role', 'app')
            ->where('status', 'active')
            ->when($caller->role !== 'gateway', fn (Builder $query): Builder => $query->whereIn('id', $visibleNodeIds))
            ->orderBy('name')
            ->limit(2)
            ->get();

        if ($nodes->count() === 1) {
            return $nodes->first();
        }

        return $this->validationFailed('node', '', 'A node or app filter is required when the visible tool target is ambiguous.');
    }

    /**
     * @return array<string, mixed>
     */
    private function toolPayload(NodeTool $tool): array
    {
        return app(ToolPayloadMapper::class)->toArray($tool);
    }

    private function authorizationFailed(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => [],
            ],
        ], 403);
    }

    private function validationFailed(string $field, string $value, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => [
                    'field' => $field,
                    'value' => $value,
                ],
            ],
        ], 400);
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
        return 'api:GET /tools/{tool}';
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

    /**
     * @return array<string, mixed>
     */
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
