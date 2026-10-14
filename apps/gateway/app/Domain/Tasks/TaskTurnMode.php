<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/**
 * How the turn command treats this turn. A consult or a relay accepts `answered`.
 * A review that follows a direction resolution requires `--cause` on every outcome.
 */
final readonly class TaskTurnMode
{
    public function __construct(
        public bool $consult = false,
        public bool $relay = false,
        public bool $causeRequired = false,
        /** A durable correction resume: preparing the same key again preserves the accepted turn and receipt. */
        public ?string $deliveryKey = null,
    ) {}

    /** One state decision for reviewer instructions and installed turn metadata. */
    public static function forTask(?Task $task, TaskThreadRole $role): ?self
    {
        if ($role !== TaskThreadRole::Reviewer || ! $task instanceof Task) {
            return null;
        }
        if ($task->consult_comment_id !== null) {
            return new self(consult: true);
        }
        if ($task->direction_relay_comment_id !== null) {
            return new self(relay: true);
        }

        return TaskQuestions::awaitsCause($task) ? new self(causeRequired: true) : null;
    }
}
