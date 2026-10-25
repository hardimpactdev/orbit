<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskCheckedTreeChanged;
use App\Domain\Tasks\TaskWorkspaceCommitReceipt;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

final readonly class RemoteTaskWorkspaceSigner implements TaskWorkspaceSigner
{
    public function __construct(
        private TaskWorkspaceExecutor $ssh,
    ) {}

    public function commit(Instance $instance, string $message, bool $allowEmpty = false, ?TaskWorkspaceCommitReceipt $receipt = null): ?string
    {
        $instance->loadMissing('node');

        if ($instance->checkout_path === '' || $message === '') {
            return null;
        }

        try {
            $result = $this->ssh->execute(
                $instance,
                new RemoteCommand(
                    arguments: ['bash', '-seu', '--', $instance->checkout_path],
                    input: "receipt='".($receipt?->ref() ?? '')."'\nexpected_head='".($receipt->head ?? '')."'\nexpected_tree='".($receipt->tree ?? '')."'\ncheckout=\$1\nmessage='".base64_encode($message)."'\nempty='".($allowEmpty ? '1' : '')."'\n".<<<'BASH'
                    export GIT_AUTHOR_NAME=orbit
                    export GIT_AUTHOR_EMAIL=tasks@orbit
                    export GIT_COMMITTER_NAME=orbit
                    export GIT_COMMITTER_EMAIL=tasks@orbit
                    git() { command git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" "$@"; }
                    if [ -n "$receipt" ]; then
                        # HEAD and the receipt move together. A lost reply can only read that same commit.
                        if saved=$(git rev-parse --verify --quiet "$receipt^{commit}"); then
                            printf '%s\n' "$saved"
                            exit 0
                        fi
                        [ "$(git rev-parse HEAD)" = "$expected_head" ] || exit 42
                        git add -A
                        [ "$(git write-tree)" = "$expected_tree" ] || exit 42
                        commit=$(printf '%s' "$message" | base64 -d | git commit-tree "$expected_tree" -p "$expected_head")
                        printf 'start\nupdate HEAD %s %s\ncreate %s %s\nprepare\ncommit\n' "$commit" "$expected_head" "$receipt" "$commit" | git update-ref --stdin >/dev/null
                        printf '%s\n' "$commit"
                        exit 0
                    fi
                    git add -A
                    if [ -n "$empty" ] || ! git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" diff --cached --quiet; then
                        printf '%s' "$message" | base64 -d | git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" commit --quiet --allow-empty --file=-
                    fi
                    git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" rev-parse --verify HEAD^{commit}
                    BASH,
                ),
                'task-workspace-commit',
                'tasks.commit_failed',
                mutates: true, changesCheckout: true,
            );
        } catch (RuntimeConvergenceException $exception) {
            if ($receipt !== null && $exception->result?->exitCode === 42) {
                throw new TaskCheckedTreeChanged('The workspace moved before the handoff commit.');
            }
            if ($receipt !== null) {
                throw new TaskWorkspaceWaiting('The handoff commit receipt must be reconciled.');
            }

            return null;
        }

        $sha = trim($result->stdout);

        if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $sha) !== 1) {
            if ($receipt !== null) {
                throw new TaskWorkspaceWaiting('The handoff commit receipt must be reconciled.');
            }

            return null;
        }

        return $sha;
    }
}
