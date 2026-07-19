<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Authorization\ServingNodeResolver;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspaceRoleGuard;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;

/**
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class RequireGrantPermission
{
    public function __construct(
        private NodeAccessAuthorizer $authorizer,
        private ServingNodeResolver $servingNodeResolver,
        private WorkspaceRoleGuard $workspaceRoleGuard,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $consumer = $request->user();

        if ($consumer instanceof Node && $this->targetsWorkspace($request)) {
            try {
                $this->workspaceRoleGuard->ensureNodeMayOperateWorkspaces($consumer);

                $serving = $this->servingNodeResolver->resolve($request, ServingNode::WorkspaceOwning);

                if ($serving instanceof Node) {
                    $this->workspaceRoleGuard->ensureNodeIsWorkspaceEligible($serving);
                }
            } catch (WorkspaceUnsupportedForProduction $exception) {
                return $this->workspaceUnsupportedForProduction($exception);
            }
        }

        $attribute = $this->attributeForRequest($request);

        if (! $attribute instanceof RequiresPermission) {
            return $next($request);
        }

        if (! $consumer instanceof Node) {
            return $this->forbidden('Peer identity unknown.');
        }

        if (str_starts_with($attribute->permission, 'workspace:')) {
            try {
                $this->workspaceRoleGuard->ensureNodeMayOperateWorkspaces($consumer);
            } catch (WorkspaceUnsupportedForProduction $exception) {
                return $this->workspaceUnsupportedForProduction($exception);
            }
        }

        $serving = $this->servingNodeResolver->resolve($request, $attribute->servingNode);

        if (! $serving instanceof Node) {
            if ($attribute->servingNode !== ServingNode::Gateway) {
                return $next($request);
            }

            return $this->forbidden('Serving node could not be resolved for this route.', [
                'reason' => 'serving_node_unresolved',
                'missing_permission' => $attribute->permission,
            ]);
        }

        if ($attribute->servingNode === ServingNode::WorkspaceOwning) {
            try {
                $this->workspaceRoleGuard->ensureNodeIsWorkspaceEligible($serving);
            } catch (WorkspaceUnsupportedForProduction $exception) {
                return $this->workspaceUnsupportedForProduction($exception);
            }
        }

        $result = $this->authorizer->authorize($consumer, $serving, $attribute->permission);

        if ($result->allowed) {
            return $next($request);
        }

        return $this->forbidden(
            "This node is not authorized for '{$attribute->permission}' on '{$serving->name}'.",
            $this->metadata($serving, $result),
        );
    }

    private function attributeForRequest(Request $request): ?RequiresPermission
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return null;
        }

        $controller = $route->getController();

        if (is_object($controller)) {
            return $this->attributeForController(
                controllerClass: $controller::class,
                method: $route->getActionMethod(),
            );
        }

        $uses = $route->getAction('uses');

        if (is_array($uses) && is_string($uses[0] ?? null) && is_string($uses[1] ?? null)) {
            return $this->attributeForController($uses[0], $uses[1]);
        }

        if (is_string($uses)) {
            return $this->attributeForActionString($uses);
        }

        return $this->attributeForActionString($route->getActionName());
    }

    private function attributeForActionString(string $action): ?RequiresPermission
    {
        if (str_contains($action, '@')) {
            [$controllerClass, $method] = explode('@', $action, 2);

            return $this->attributeForController($controllerClass, $method);
        }

        if (class_exists($action)) {
            return $this->attributeForController($action, '__invoke');
        }

        return null;
    }

    private function attributeForController(string $controllerClass, string $method): ?RequiresPermission
    {
        if (! class_exists($controllerClass)) {
            return null;
        }

        if (method_exists($controllerClass, $method)) {
            $methodAttribute = $this->firstAttribute(new ReflectionMethod($controllerClass, $method));

            if ($methodAttribute instanceof RequiresPermission) {
                return $methodAttribute;
            }
        }

        return $this->firstAttribute(new ReflectionClass($controllerClass));
    }

    private function targetsWorkspace(Request $request): bool
    {
        if ($this->isWorkspaceTarget($request->route('workspace'))) {
            return true;
        }

        return $this->isWorkspaceTarget($request->input('workspace'));
    }

    private function isWorkspaceTarget(mixed $workspace): bool
    {
        if ($workspace instanceof Workspace) {
            return true;
        }

        return is_string($workspace) && trim($workspace) !== '';
    }

    private function firstAttribute(ReflectionClass|ReflectionMethod $reflection): ?RequiresPermission
    {
        $attributes = $reflection->getAttributes(RequiresPermission::class);

        if ($attributes === []) {
            return null;
        }

        $attribute = $attributes[0]->newInstance();

        return $attribute instanceof RequiresPermission ? $attribute : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function forbidden(string $message, array $meta = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => $meta,
            ],
        ], 403);
    }

    private function workspaceUnsupportedForProduction(
        WorkspaceUnsupportedForProduction $exception,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'meta' => $exception->meta,
            ],
        ], 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Node $serving, AuthorizationResult $result): array
    {
        return [
            'reason' => $result->reason,
            'missing_permission' => $result->missingPermission,
            'serving_node' => $serving->name,
        ];
    }
}
