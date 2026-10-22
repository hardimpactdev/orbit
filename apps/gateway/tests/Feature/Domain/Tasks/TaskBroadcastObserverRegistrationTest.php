<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskBroadcastObserver;
use App\Models\AgentThread;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Events\Dispatcher;

it('broadcasts every change of a task model through the task broadcast observer', function (string $model): void {
    // Booting the model registers the observers it declares.
    new $model;
    $dispatcher = app('events');
    expect($dispatcher)->toBeInstanceOf(Dispatcher::class);

    foreach (['created', 'updated', 'deleted'] as $event) {
        expect($dispatcher->getRawListeners()["eloquent.{$event}: {$model}"] ?? [])
            ->toContain(TaskBroadcastObserver::class.'@'.$event);
    }
})->with([Task::class, TaskCheck::class, TaskComment::class, AgentThread::class]);
