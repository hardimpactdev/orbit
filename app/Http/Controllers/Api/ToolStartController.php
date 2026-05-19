<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Api\Concerns\ResolvesVisibleToolNodes;
use App\Http\Controllers\Api\Concerns\StreamsToolActionProgress;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Tools\AgentToolAuthorizer;
use App\Services\Tools\ToolLifecycleManager;
use App\Services\Tools\ToolRegistryFailure;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ToolStartController implements Loggable
{
    use ResolvesVisibleToolNodes;
    use StreamsToolActionProgress;

    private ?NodeTool $activitySubject = null;

    public function __invoke(
        Request $request,
        string $tool,
        ToolLifecycleManager $lifecycle,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $visibleNodeIds = $this->visibleToolNodeIds($caller, false, 'tool:start');

        if (! $this->nodeRoleAssignments()->nodeIsGateway($caller) && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to manage tools.');
        }

        $target = $this->authorizedToolTarget($request, $caller, $visibleNodeIds);

        if ($target instanceof JsonResponse) {
            return $target;
        }

        $node = $target['node'];
        $app = $target['app'];

        $agentSelfAuth = $this->authorizeAgentToolAction($caller, $node, $tool, 'start');

        if ($agentSelfAuth instanceof JsonResponse) {
            return $agentSelfAuth;
        }

        $meta = (object) [];

        if ($node !== null) {
            $targetNode = Node::query()->where('name', $node)->where('status', 'active')->first();

            if ($targetNode instanceof Node) {
                $warning = app(AgentToolAuthorizer::class)->multipleAgentToolsWarning($targetNode, $tool);

                if ($warning !== null) {
                    $meta = [
                        'warnings' => [$warning],
                    ];
                }
            }
        }

        $operation = fn (): array|ToolRegistryFailure => $lifecycle->start($tool, node: $node, app: $app);

        if ($this->wantsEventStream($request)) {
            return $this->streamToolAction(
                streams: $streams,
                title: 'Starting Tool',
                doneFooter: 'Tool started',
                failFooter: 'Tool start failed',
                operation: $operation,
                data: fn (array $result): array => ['tool' => $result],
                exitCode: fn (): int => 0,
            );
        }

        $result = $operation();

        if ($result instanceof ToolRegistryFailure) {
            return $this->failureResponse($result);
        }

        $this->activitySubject = NodeTool::query()
            ->where('name', $tool)
            ->whereHas('node', fn ($query) => $query->where('name', $result['node'] ?? null))
            ->first();

        return response()->json([
            'success' => [
                'data' => [
                    'tool' => $result,
                ],
                'meta' => $meta,
            ],
        ]);
    }

    private function failureResponse(ToolRegistryFailure $failure): JsonResponse
    {
        $status = match ($failure->code) {
            'tool.not_found' => 404,
            'authorization_failed' => 403,
            'tool.remote_action_failed' => 502,
            default => 400,
        };

        return response()->json([
            'error' => [
                'code' => $failure->code,
                'message' => $failure->message,
                'meta' => $failure->meta === [] ? (object) [] : $failure->meta,
            ],
        ], $status);
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
        return 'api:POST /tools/{tool}/start';
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
