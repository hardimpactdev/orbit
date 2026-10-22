<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Infrastructure\SourceControl\WorkspaceGit;

/**
 * Shell programs that deploy a development default in its own checkout. Each runs as the managed
 * user with the positional arguments home, repository URL, and Instance ID, then its own arguments.
 *
 * Every Git call runs with hooks and fsmonitor off. The fetch carries the read credential only for itself, with no
 * credential helper and the explicit repository URL. A deploy step is the Project's own command and runs as the
 * managed user by design.
 */
final class DevelopmentCheckoutProgram
{
    /** Exit status when the checkout has uncommitted changes to tracked files. */
    public const int Dirty = 3;

    /** Exit status when the local branch has commits that the target commit does not contain. */
    public const int Diverged = 4;

    /** Exit status when the fetched branch does not contain the requested commit. */
    public const int NotOnBranch = 5;

    /** Exit status when a setup or teardown step holds a lifecycle lock that the program needs. */
    public const int Busy = 75;

    private static function guard(): string
    {
        return <<<'BASH'
            home=$1
            repository=$2
            instance=$3
            shift 3
            test -d "$home" && test ! -L "$home" || exit 1
            test "$(realpath -e -- "$home")" = "$home"
            test -d "$home/.git" && test ! -L "$home/.git" || exit 1
            test "$(stat -c %u -- "$home")" = "$(id -u)"
            test "$(stat -c %u -- "$home/.git")" = "$(id -u)"
            git() { command git -c core.hooksPath=/dev/null -c core.fsmonitor=false --literal-pathspecs "$@"; }
            test "$(git -C "$home" config --get remote.origin.url)" = "$repository"
            test "$(git -C "$home" rev-parse --git-common-dir)" = .git
            # Setup and teardown steps hold this lock, a flock on their checkout directory, while they run.
            # `lock_fd` names the last lock taken, so a process that outlives the program can be started without it.
            lifecycle_lock() {
                local checkout=$1
                test -d "$checkout" && test ! -L "$checkout" || exit 1
                exec {lock_fd}<"$checkout"
                test ! -L "$checkout" || exit 1
                test "$(stat -c '%d:%i' -- "$checkout")" = "$(stat -L -c '%d:%i' -- "/proc/self/fd/$lock_fd")"
                flock -n -x "$lock_fd" || exit 75
            }
            refuse_dirty() {
                local changes
                changes=$(git -C "$home" status --porcelain --untracked-files=no)
                if [ -n "$changes" ]; then
                    printf 'The checkout has uncommitted changes to tracked files:\n%s\n' "$changes" | head -n 21 >&2
                    exit 3
                fi
            }
            # `checkout -B` would drop local commits on the branch that the target commit does not contain.
            refuse_diverged() {
                local branch=$1 commit=$2
                if git -C "$home" rev-parse --verify --quiet "refs/heads/$branch^{commit}" >/dev/null \
                    && ! git -C "$home" merge-base --is-ancestor "refs/heads/$branch" "$commit"; then
                    printf 'The local branch %s has commits that %s does not contain.\n' "$branch" "$commit" >&2
                    exit 4
                fi
            }
            # Checkout leaves untracked and ignored files in a directory that the commit removes, such as the
            # dependencies of a removed package. Remove them first, so a later copy of the checkout as a seed
            # finds only directories the commit has. Every read goes to a file first, so a failed read stops.
            drop_removed_directories() {
                local commit=$1 entries entry parent tree
                entries=$(mktemp "$home/.git/orbit-untracked.XXXXXXXX")
                git -C "$home" ls-files -z --others --directory > "$entries"
                while IFS= read -r -d '' entry; do
                    entry=${entry%/}
                    parent=$(dirname -- "$entry")
                    if [ "$parent" = . ]; then continue; fi
                    tree=$(git -C "$home" ls-tree -d "$commit" -- "$parent")
                    if [ -n "$tree" ]; then continue; fi
                    if [ -d "$home/$entry" ] && [ ! -L "$home/$entry" ]; then chmod -R u+w -- "$home/$entry"; fi
                    rm -rf -- "${home:?}/$entry"
                done < "$entries"
                rm -f -- "$entries"
            }
            BASH;
    }

    /**
     * Fetches the branch and prints its commit. With a second argument, it prints that commit instead, after it
     * proved that the fetched branch contains it; otherwise it exits with {@see self::NotOnBranch}.
     */
    public static function target(): string
    {
        return self::guard().WorkspaceGit::fetchPreamble().<<<'BASH'

            branch=$1
            commit=${2-}
            checkout_fetch -C "$home" fetch --quiet --no-tags -- "$repository" \
                "+refs/heads/$branch:refs/remotes/origin/$branch" >/dev/null
            if [ -z "$commit" ]; then
                git -C "$home" rev-parse --verify "refs/remotes/origin/$branch^{commit}"
                exit 0
            fi
            printf '%s' "$commit" | grep -Eq '^([0-9a-f]{40}|[0-9a-f]{64})$'
            git -C "$home" merge-base --is-ancestor "$commit" "refs/remotes/origin/$branch" >/dev/null 2>&1 || exit 5
            printf '%s\n' "$commit"
            BASH;
    }

    /** Checks out the commit on the branch in place and prints the commit. */
    public static function checkout(): string
    {
        return self::guard().<<<'BASH'

            branch=$1
            commit=$2
            printf '%s' "$commit" | grep -Eq '^([0-9a-f]{40}|[0-9a-f]{64})$'
            lifecycle_lock "$home"
            refuse_dirty
            refuse_diverged "$branch" "$commit"
            drop_removed_directories "$commit"
            git -C "$home" checkout --quiet --no-track -B "$branch" "$commit"
            test "$(git -C "$home" symbolic-ref --short HEAD)" = "$branch"
            git -C "$home" rev-parse --verify HEAD
            BASH;
    }

    /** Runs one step in the checkout. The command arrives base64-encoded in place of `__COMMAND__`. */
    public static function step(): string
    {
        return self::guard().<<<'BASH'

            timeout_seconds=$1
            lifecycle_lock "$home"
            command_file=$(mktemp "$home/.git/orbit-deploy-step.XXXXXXXX")
            printf '%s' '__COMMAND__' | base64 --decode > "$command_file"
            supervisor=
            watchdog=
            cleanup() {
                status=$?
                trap - EXIT HUP INT TERM
                if [ -n "$supervisor" ]; then
                    if kill -TERM -- "-$supervisor" 2>/dev/null; then
                        sleep 0.1
                        kill -KILL -- "-$supervisor" 2>/dev/null || true
                    fi
                    wait "$supervisor" 2>/dev/null || true
                fi
                if [ -n "$watchdog" ]; then
                    kill "$watchdog" 2>/dev/null || true
                    wait "$watchdog" 2>/dev/null || true
                fi
                rm -f -- "$command_file"
                exit "$status"
            }
            trap cleanup EXIT
            trap 'exit 143' HUP INT TERM
            # Neither the step nor the watchdog keeps the lock, so a daemon or a stray `sleep` cannot refuse the next step.
            setsid bash -eu -c 'cd -- "$1"; exec bash -eu "$2"' bash "$home" "$command_file" {lock_fd}>&- &
            supervisor=$!
            # Wait for setsid before using a negative process-group ID.
            for attempt in $(seq 1 100); do
                group=$(ps -o pgid= -p "$supervisor" | tr -d ' ' || true)
                if [ "$group" = "$supervisor" ]; then break; fi
                if ! kill -0 "$supervisor" 2>/dev/null; then break; fi
                sleep 0.01
            done
            # A separate watchdog session survives loss of the SSH shell's process group.
            setsid bash -c '
                owner=$1
                group=$2
                expires=$((SECONDS + $3))
                while kill -0 "$owner" 2>/dev/null && kill -0 "$group" 2>/dev/null && [ "$SECONDS" -lt "$expires" ]; do sleep 0.1; done
                if kill -TERM -- "-$group" 2>/dev/null; then
                    sleep 0.1
                    kill -KILL -- "-$group" 2>/dev/null || true
                fi
            ' bash "$$" "$supervisor" "$timeout_seconds" {lock_fd}>&- &
            watchdog=$!
            if wait "$supervisor"; then status=0; else status=$?; fi
            exit "$status"
            BASH;
    }
}
