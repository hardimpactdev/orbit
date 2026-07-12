<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Php\UsePhpRuntime;
use App\Contracts\Loggable;
use App\Data\Php\PhpRuntimeFailure;
use App\Enums\ActivityLogType;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Services\Authorization\ServingNodeResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class PhpUseController implements Loggable
{
    public function __construct(
        private UsePhpRuntime $php,
        private ServingNodeResolver $servingNodeResolver,
        private NodeAccessAuthorizer $authorizer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->failure(new PhpRuntimeFailure('authorization_failed', 'Peer identity unknown.'));
        }

        $servingNode = $this->servingNode($request);

        if (! $servingNode instanceof Node) {
            return $this->failure(new PhpRuntimeFailure(
                'authorization_failed',
                'Serving node could not be resolved for the PHP runtime target.',
                [
                    'reason' => 'serving_node_unresolved',
                    'missing_permission' => 'php:write',
                ],
            ));
        }

        $authorization = $this->authorizer->authorize($caller, $servingNode, 'php:write');

        if (! $authorization->allowed) {
            return $this->failure(new PhpRuntimeFailure(
                'authorization_failed',
                "This node is not authorized for 'php:write' on '{$servingNode->name}'.",
                [
                    'reason' => $authorization->reason,
                    'missing_permission' => $authorization->missingPermission,
                    'serving_node' => $servingNode->name,
                ],
            ));
        }

        $result = $this->php->handle(
            version: $this->nullableString($request->input('version')),
            app: $this->nullableString($request->input('app')),
            workspace: $this->nullableString($request->input('workspace')),
            node: $this->nullableString($request->input('node')),
            inherit: filter_var($request->input('inherit'), FILTER_VALIDATE_BOOL),
            cli: filter_var($request->input('cli'), FILTER_VALIDATE_BOOL),
        );

        if ($result->failed()) {
            return $this->failure($result->failure);
        }

        return response()->json([
            'success' => [
                'data' => $result->payload,
                'meta' => $result->meta,
            ],
        ]);
    }

    private function servingNode(Request $request): ?Node
    {
        if ($this->nullableString($request->input('workspace')) !== null) {
            return $this->servingNodeResolver->resolve($request, ServingNode::WorkspaceOwning);
        }

        if ($this->nullableString($request->input('app')) !== null) {
            return $this->servingNodeResolver->resolve($request, ServingNode::AppInstanceOwning);
        }

        return $this->servingNodeResolver->resolve($request, ServingNode::Target);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function failure(?PhpRuntimeFailure $failure): JsonResponse
    {
        $failure ??= new PhpRuntimeFailure('validation_failed', 'Required input is missing or invalid.');

        return response()->json(
            [
                'error' => [
                    'code' => $failure->code,
                    'message' => $failure->message,
                    'meta' => $failure->meta,
                ],
            ],
            $failure->code === 'authorization_failed' ? 403 : 400,
        );
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
        return 'api:POST /php/use';
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
