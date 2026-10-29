<?php

declare(strict_types=1);

namespace App\Http\Middleware\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Routes\RouteParentExecution;
use App\Models\Operation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keep protected hydration, dispatch and projection under the same environment lock. */
final readonly class SerializeInstanceEnvironment
{
    public function __construct(private InstanceEnvironmentOperationLock $locks) {}

    public function handle(Request $request, Closure $next): Response
    {
        $operation = $request->route('operation');
        if (! $operation instanceof Operation || $operation->kind !== 'instance.sync_environment') {
            return $next($request);
        }

        $parent = $operation;
        while ($parent instanceof Operation) {
            if (in_array($parent->kind, RouteParentExecution::Kinds, true)) {
                // The Route middleware must acquire its complete owner set before subset reentry.
                return $next($request);
            }
            $parent = $parent->parent_operation_id === null ? null : Operation::query()->find($parent->parent_operation_id);
        }

        return $this->locks->run([$operation->target_id], fn (): Response => $next($request));
    }
}
