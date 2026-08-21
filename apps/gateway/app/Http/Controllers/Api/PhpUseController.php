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
            instance: $this->nullableString($request->input('instance')),
            workspace: $this->nullableString($request->input('workspace')),
            node: $this->nullableString($request->input('node')),
            cli: filter_var($request->input('cli'), FILTER_VALIDATE_BOOL),
            caller: $caller,
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

        if ($this->nullableString($request->input('instance')) !== null) {
            return $this->servingNodeResolver->resolve($request, ServingNode::InstanceOwning);
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

        $status = match ($failure->code) {
            'authorization_failed' => 403,
            'workspace.unsupported_for_production' => 422,
            default => 400,
        };

        return response()->json(
            [
                'error' => [
                    'code' => $failure->code,
                    'message' => $failure->message,
                    'meta' => $failure->meta,
                ],
            ],
            $status,
        );
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /php/use';
    }

    public function subject(): ?Model
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
