<?php

declare(strict_types=1);

namespace App\Infrastructure\SourceControl;

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

        return "workspace_git() (\n".$guard.<<<'BASH'
            unset GIT_CONFIG_COUNT GIT_CONFIG_PARAMETERS GIT_ASKPASS SSH_ASKPASS GH_TOKEN GITHUB_TOKEN
            for variable in ${!GIT_CONFIG_KEY_@} ${!GIT_CONFIG_VALUE_@}; do unset "$variable"; done

            BASH.'if [ "${1:-}" = -C ]; then'."\n".
            $invoke.' -c core.hooksPath=/dev/null -c core.fsmonitor=false'.$trust.' "$@"'."\nelse\n".
            $invoke.' -c core.hooksPath=/dev/null -c core.fsmonitor=false "$@"'."\nfi\n)\n".self::reclaim($worker);
    }

    /**
     * `workspace_reclaim <checkout>` gives the work tree entries that the worker's Git wrote back to the managed user,
     * who must own them for later ACL grants such as Caddy access. Root changes only worker-owned entries, never
     * follows a symlink, and leaves `.git` alone, so the worker keeps its private check directories there.
     */
    private static function reclaim(?string $worker): string
    {
        if ($worker === null) {
            return "workspace_reclaim() { :; }\n";
        }

        return "workspace_reclaim() {\n".
            '    find -P "$1" -mindepth 1 -maxdepth 1 -xdev ! -name .git -exec sudo -n chown -h -R -P --from='.escapeshellarg($worker).' "$(id -un):$(id -gn)" -- {} +'."\n}\n";
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
     * `isolated_fetch <checkout> <url> <prune 0|1> <refspec>...` then copies the fetched refs into the checkout with a
     * local fetch through `workspace_git`, as the worker on a shared checkout. That step needs no credentials.
     * `isolated_push <checkout> <url> <refspec>...` pushes from the private repository. Both need `git_read`;
     * `isolated_fetch` also needs the worker preamble.
     */
    public static function networkPreamble(): string
    {
        return <<<'BASH'
            isolated_repository() {
                common=$(git -C "$1" rev-parse --path-format=absolute --git-common-dir)
                test -d "$common/objects"
                scratch=$(mktemp -d)
                trap 'rm -rf -- "$scratch"' EXIT
                command git init --quiet --bare -- "$scratch/repository.git"
                printf '%s\n' "$common/objects" > "$scratch/repository.git/objects/info/alternates"
            }
            isolated_git() {
                git_read git -c credential.helper= -c http.followRedirects=false -c core.hooksPath=/dev/null \
                    -c core.fsmonitor=false -c protocol.ext.allow=never --git-dir="$scratch/repository.git" "$@"
            }
            isolated_fetch() (
                checkout=$1
                url=$2
                prune=()
                if [ "$3" = 1 ]; then prune=(--prune); fi
                shift 3
                isolated_repository "$checkout"
                isolated_git fetch --quiet --no-tags -- "$url" "$@"
                copy=()
                for spec in "$@"; do
                    destination=${spec#*:}
                    copy+=("+$destination:$destination")
                done
                # The worker may read the private repository now. It cannot write it.
                chmod -R go+rX -- "$scratch"
                workspace_git -C "$checkout" -c safe.directory="$scratch/repository.git" fetch --quiet --no-tags "${prune[@]}" \
                    -- "$scratch/repository.git" "${copy[@]}"
            )
            isolated_push() (
                checkout=$1
                url=$2
                shift 2
                isolated_repository "$checkout"
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
