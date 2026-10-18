<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\Task;

/** All turn entry points share admission, including operator resolutions and reminders. */
final readonly class LeasedAgentDriver implements AgentDriver
{
    public function __construct(private AgentDriver $driver) {}

    public function key(): string
    {
        return $this->driver->key();
    }

    public function create(AgentThreadStart $intent): string
    {
        $pending = $intent->threadId !== null ? AgentThread::query()->find($intent->threadId) : null;
        $group = $pending instanceof AgentThread ? Task::topLevel()->find($pending->task_group_id) : null;
        if ($group instanceof Task && $group->taskable_id === $intent->workspace->id
            && $pending->role === $intent->role->value) {
            app(TaskWorkspaceLease::class)->turn($group, $intent->role, 'thread:'.$pending->id, $pending->id);
            if (! $intent->deferOpeningTurn) {
                app(TaskWorkspaceLease::class)->dispatch($pending, key: $intent->openingKey, message: $intent->prompt);
            }
        } elseif (Task::topLevel()->where('taskable_id', $intent->workspace->id)->exists()) {
            throw new AgentDriverException('The task turn has no unambiguous workspace owner.');
        }

        try {
            return $this->driver->create($intent);
        } catch (AgentDriverException $exception) {
            if ($exception->turnRejected && $pending instanceof AgentThread) {
                app(TaskWorkspaceLease::class)->rejectedDispatch($pending);
            }
            throw $exception;
        }
    }

    public function send(AgentThread $thread, string $message, ?string $key = null): void
    {
        $leases = app(TaskWorkspaceLease::class);
        $group = Task::topLevel()->findOrFail($thread->task_group_id);
        app(TaskExecutionLock::class)->synchronized($group->id, function () use ($leases, $thread, $message, $key): void {
            $leases->reconcile(Task::topLevel()->findOrFail($thread->task_group_id));
            if ($leases->completedSend($thread, $key, $message)) {
                return;
            }
            $leases->withThreadLock($thread, function () use ($leases, $thread, $message, $key): void {
                $continuation = $leases->inputContinuation($thread);
                $retry = ! $continuation && $leases->retrying($thread, $key, $message);
                if (! $retry && ! $continuation) {
                    $this->admit($thread);
                    $previous = null;
                    try {
                        $previous = $this->driver->observe($thread);
                    } catch (AgentDriverException) {
                        // An unreadable prior turn does not weaken the completion fence.
                    }
                    $key = $leases->dispatch($thread, $previous, $key, $message);
                } else {
                    $key = $leases->admittedSendKey($thread, $key, $message, $continuation);
                }
                try {
                    $this->driver->send($thread, $message, $key);
                } catch (AgentDriverException $exception) {
                    if ($exception->turnRejected && ! $retry && ! $continuation) {
                        $leases->rejectedDispatch($thread);
                    }
                    throw $exception;
                }
            });
        }, wait: false);
    }

    public function respond(AgentThread $thread, AgentInputRequest $request, array $answers): void
    {
        $group = Task::topLevel()->find($thread->task_group_id);
        if (! $group instanceof Task) {
            $this->driver->respond($thread, $request, $answers);

            return;
        }
        $leases = app(TaskWorkspaceLease::class);
        $leases->withThreadLock($thread, function () use ($leases, $group, $thread, $request, $answers): void {
            $leases->acquire($group, $thread->role, 'thread:'.$thread->id, $thread->id);
            $this->driver->respond($thread, $request, $answers);
        });
    }

    private function admit(AgentThread $thread): void
    {
        $group = Task::topLevel()->find($thread->task_group_id);
        $role = TaskThreadRole::tryFrom($thread->role);
        if ($group instanceof Task && $role !== null) {
            app(TaskWorkspaceLease::class)->turn($group, $role, 'thread:'.$thread->id, $thread->id);
        }
    }

    public function interrupt(AgentThread $thread): void
    {
        $this->driver->interrupt($thread);
    }

    public function archive(AgentThread $thread, string $commandId): void
    {
        $this->driver->archive($thread, $commandId);
    }

    public function observe(AgentThread $thread): AgentObservation
    {
        return $this->driver->observe($thread);
    }

    public function read(AgentThread $thread, ?string $cursor): AgentObservation
    {
        return $this->driver->read($thread, $cursor);
    }

    public function events(AgentThread $thread, ?string $cursor, ?float $timeoutSeconds = null): iterable
    {
        return $this->driver->events($thread, $cursor, $timeoutSeconds);
    }
}
