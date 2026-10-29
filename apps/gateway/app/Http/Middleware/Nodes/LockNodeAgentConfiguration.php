<?php

declare(strict_types=1);

namespace App\Http\Middleware\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Http\Requests\Operations\RunExecutorRequest;
use App\Infrastructure\Nodes\NodeLocks;
use App\Models\Operation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Holds the agent lock until Kit H and the keyed receipt transaction finish. */
final readonly class LockNodeAgentConfiguration
{
    public function __construct(private NodeLocks $locks) {}

    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $operation = $request->route('operation');
        if (! $operation instanceof Operation || $operation->kind !== 'node.converge'
            || $operation->node_id === null || $request->input('transport') !== 'agent' || $request->input('name') !== 'agent.configure') {
            return $next($request);
        }
        // Resolve authorization and validation before acquiring an owner-bound lock.
        app(RunExecutorRequest::class);
        $lock = $this->locks->lock('node-agent:id:'.$operation->node_id, $this->locks->operationSeconds());
        if (! $lock->get()) {
            throw new ResourceOperationException('agent.converge_busy', 'Another Node agent converge is running.', 409);
        }
        try {
            return $next($request);
        } finally {
            $lock->release();
        }
    }
}
