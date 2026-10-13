<?php

declare(strict_types=1);

namespace App\Infrastructure\SourceControl;

use App\Infrastructure\TaskVms\TaskVmGit;
use App\Models\Instance;

/** Suppress checkout-controlled hooks and fsmonitor for Gateway shell reads and mutations. */
final readonly class WorkspaceGit
{
    /** Content-reading Git can start filters. Keep it separate from credential-bearing git_read. */
    public static function workerPreamble(?string $worker): string
    {
        $invoke = $worker === null ? 'command git' : 'sudo -n -u '.escapeshellarg($worker).' -H -- git';
        $trust = $worker === null ? '' : ' -c safe.directory="$2"';
        $guard = $worker === null ? '' : 'worker_uid=$(id -u '.escapeshellarg($worker).') || exit 126'."\n".
            'test "$worker_uid" != 0 && test "$worker_uid" != "$(id -u)" || exit 126'."\n";

        return 'workspace_worker='.escapeshellarg($worker ?? '')."\n"."workspace_git() (\n".$guard.<<<'BASH'
            unset GIT_CONFIG_COUNT GIT_CONFIG_PARAMETERS GIT_ASKPASS SSH_ASKPASS GH_TOKEN GITHUB_TOKEN
            for variable in ${!GIT_CONFIG_KEY_@} ${!GIT_CONFIG_VALUE_@}; do unset "$variable"; done

            BASH.'if [ "${1:-}" = -C ]; then'."\n".
            $invoke.' -c core.hooksPath=/dev/null -c core.fsmonitor=false'.$trust.' "$@"'."\nelse\n".
            $invoke.' -c core.hooksPath=/dev/null -c core.fsmonitor=false "$@"'."\nfi\n)\n";
    }

    /**
     * The Git directory that holds a development Instance's objects and refs: the seed's for a worktree of a seed,
     * otherwise the checkout's own. The Gateway derives it from its records, never from `.git/commondir` or a `.git`
     * file, which the worker can rewrite on a shared checkout.
     */
    public static function commonDirectory(Instance $instance): string
    {
        return rtrim($instance->seed_repository ?? $instance->checkout_path, '/').'/.git';
    }

    /**
     * Network Git with credentials never reads a checkout's `.git/config`. The worker can write that file on a shared
     * checkout, and keys such as `credential.helper`, `core.sshCommand`, `remote.*.uploadpack`, `url.*.insteadOf` with
     * `ext::`, or an `include.path` that pulls them in, would run a command as the managed user or send the token
     * elsewhere. Overriding single keys with `-c` cannot cover includes or every URL-scoped key, so the network call
     * runs in a private bare repository instead: `mktemp -d`, a fresh config, the explicit repository URL, and the
     * checkout's objects as an alternate so a fetch transfers only what is missing. The token stays in the managed
     * user's process.
     *
     * The caller names the Git directory whose objects the private repository borrows, from Gateway records. The
     * program never asks Git for it: `rev-parse --git-common-dir` follows `.git/commondir` and a `.git` file, which
     * the worker can rewrite. The directory must be that exact path, a real directory, and owned by the managed user.
     * By default a fetch from a repository with alternates runs `git for-each-ref` in each alternate, a child that
     * would read the alternate's agent-writable config with the token in its environment.
     * `core.alternateRefsCommand=true` replaces that child with `true`, so no Git runs in an alternate. Objects that
     * an alternate chain names are only read; they never decide what is sent or to where.
     *
     * `isolated_fetch <checkout> <git dir> <url> <prune 0|1> <refspec>...` then copies the fetched refs into the
     * checkout with a local fetch through `workspace_git`, as the worker on a shared checkout. That step needs no
     * credentials. An ACL lets only the worker read the private repository for it. `isolated_push <git dir> <url>
     * <refspec>...` pushes from the private repository. Both need `git_read`; `isolated_fetch` also needs the worker
     * preamble.
     *
     * Task VMs never run these: the Gateway fetches and pushes for them in its own mirror ({@see TaskVmGit}). Only shared
     * checkouts need the private repository, so it goes with shared mode, and a plain fetch replaces it.
     */
    public static function networkPreamble(): string
    {
        return <<<'BASH'
            isolated_repository() {
                common=$1
                case "$common" in /*/.git) ;; *) return 1 ;; esac
                test -d "$common" && test ! -L "$common" || return 1
                test "$(realpath -e -- "$common")" = "$common" || return 1
                test "$(stat -c %u -- "$common")" = "$(id -u)" || return 1
                test -d "$common/objects" && test ! -L "$common/objects" || return 1
                test "$(stat -c %u -- "$common/objects")" = "$(id -u)" || return 1
                scratch=$(mktemp -d)
                trap 'rm -rf -- "$scratch"' EXIT
                command git init --quiet --bare -- "$scratch/repository.git"
                printf '%s\n' "$common/objects" > "$scratch/repository.git/objects/info/alternates"
            }
            isolated_git() {
                git_read git -c credential.helper= -c http.followRedirects=false -c core.hooksPath=/dev/null \
                    -c core.fsmonitor=false -c protocol.ext.allow=never -c core.alternateRefsCommand=true \
                    --git-dir="$scratch/repository.git" "$@"
            }
            isolated_fetch() (
                checkout=$1
                url=$3
                prune=()
                if [ "$4" = 1 ]; then prune=(--prune); fi
                isolated_repository "$2"
                shift 4
                isolated_git fetch --quiet --no-tags -- "$url" "$@"
                copy=()
                for spec in "$@"; do
                    destination=${spec#*:}
                    copy+=("+$destination:$destination")
                done
                # Only the worker may read the private repository now, and it cannot write it.
                if [ -n "$workspace_worker" ]; then
                    setfacl -R -m "u:$workspace_worker:rX" -- "$scratch"
                fi
                workspace_git -C "$checkout" -c safe.directory="$scratch/repository.git" -c core.alternateRefsCommand=true \
                    fetch --quiet --no-tags "${prune[@]}" -- "$scratch/repository.git" "${copy[@]}"
            )
            isolated_push() (
                url=$2
                isolated_repository "$1"
                shift 2
                isolated_git push --quiet -- "$url" "$@"
            )

            BASH;
    }

    public static function bashPreamble(?string $checkout = null): string
    {
        $trust = $checkout === null ? '' : ' -c '.escapeshellarg('safe.directory='.$checkout);

        return 'git() { command git -c core.hooksPath=/dev/null -c core.fsmonitor=false'.$trust.' "$@"; }'."\n";
    }
}
