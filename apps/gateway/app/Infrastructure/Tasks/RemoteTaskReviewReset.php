<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskReviewReset;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

final readonly class RemoteTaskReviewReset implements TaskReviewReset
{
    public function __construct(private TaskWorkspaceExecutor $ssh) {}

    public function reset(Instance $instance, string $commit): void
    {
        if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1 || $instance->checkout_path === '') {
            throw new ResourceOperationException('tasks.review_reset_failed', 'The checked commit or workspace is unavailable.');
        }
        $git = ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', $instance->checkout_path];
        foreach ([['reset', '--hard', $commit, '--'], ['clean', '-fd']] as $arguments) {
            $this->ssh->execute($instance, new RemoteCommand(
                arguments: [...$git, ...$arguments],
            ), 'task-review-reset', 'tasks.review_reset_failed', mutates: true, changesCheckout: true);
        }
    }
}
