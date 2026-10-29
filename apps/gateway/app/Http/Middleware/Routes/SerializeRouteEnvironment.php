<?php

declare(strict_types=1);

namespace App\Http\Middleware\Routes;

use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Routes\RouteEnvironmentOperationLock;
use App\Domain\Routes\RouteParentExecution;
use App\Models\Operation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Serializes a Route parent's Node effects with the existing synchronous Instance executors. */
final readonly class SerializeRouteEnvironment
{
    public function __construct(private InstanceEnvironmentOperationLock $locks) {}

    public function handle(Request $request, Closure $next): Response
    {
        $operation = $request->route('operation');
        $parent = $operation instanceof Operation ? $operation : null;
        while ($parent instanceof Operation && ! in_array($parent->kind, RouteParentExecution::Kinds, true)) {
            $parent = $parent->parent_operation_id === null ? null : Operation::query()->find($parent->parent_operation_id);
        }
        if (! $parent instanceof Operation) {
            return $next($request);
        }

        return $this->locks->run(RouteEnvironmentOperationLock::instanceIds($parent), fn (): Response => $next($request));
    }
}
