<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskGroupStatus: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case Reserved = 'reserved';
    case Running = 'running';
    case Reviewing = 'reviewing';
    case Settling = 'settling';
    case WaitingForReview = 'waiting_for_review';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public static function active(): array
    {
        return [
            self::Reserved,
            self::Running,
            self::Reviewing,
            self::Settling,
            self::WaitingForReview,
        ];
    }

    /** @return list<self> */
    public static function awaitingCompletion(): array
    {
        return [self::Settling, self::WaitingForReview];
    }

    /**
     * A group that has completed, failed, or been cancelled.
     *
     * @return list<self>
     */
    public static function endedStatuses(): array
    {
        return [self::Completed, self::Failed, self::Cancelled];
    }

    /**
     * Statuses whose failed workspace removal the scheduler can retry.
     *
     * @return list<self>
     */
    public static function workspaceRemovalStatuses(): array
    {
        return [...self::endedStatuses(), ...self::awaitingCompletion()];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }
}
