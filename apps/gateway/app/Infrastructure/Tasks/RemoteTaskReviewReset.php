<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskReviewReset;
use App\Infrastructure\SourceControl\WorkspaceGit;
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
        $this->ssh->execute($instance, new RemoteCommand(
            arguments: ['bash', '-seu', '--', $instance->checkout_path, $commit],
            input: WorkspaceGit::bashPreamble().<<<'BASH'
                cd -- "$1"
                git reset --hard "$2" --
                git clean -fd
                BASH,
        ), 'task-review-reset', 'tasks.review_reset_failed', mutates: true, changesCheckout: true);
    }
}
