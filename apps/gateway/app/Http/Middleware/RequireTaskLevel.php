<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Task;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireTaskLevel
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $level): Response
    {
        $task = $request->route('task');
        if (! $task instanceof Task) {
            abort(404);
        }
        if (($task->parent_id === null) !== ($level === 'parent')) {
            throw new ResourceOperationException('task.level_invalid', 'This action requires a '.$level.' task.', 409);
        }

        return $next($request);
    }
}
