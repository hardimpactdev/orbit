<?php

declare(strict_types=1);

namespace App\Http\Middleware\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
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

        return $this->locks->run([$operation->target_id], fn (): Response => $next($request));
    }
}
