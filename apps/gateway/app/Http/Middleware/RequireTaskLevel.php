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
    /**
     * Level `work` takes a subtask, or a task with no subtask by its own id: the route then gets the task's work row
     * (docs/reference/tasks.md#a-task-with-no-subtask).
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $level): Response
    {
        $task = $request->route('task');
        if (! $task instanceof Task) {
            abort(404);
        }
        if ($level === 'work') {
            $work = $task->parent_id === null ? $task->work()->first() : null;
            if ($work instanceof Task) {
                $request->route()?->setParameter('task', $work);

                return $next($request);
            }
            $level = 'child';
        }
        if (($task->parent_id === null) !== ($level === 'parent')) {
            throw new ResourceOperationException('task.level_invalid', 'This action requires a '.$level.' task.', 409);
        }

        return $next($request);
    }
}
