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
            '    find -P "$1" -mindepth 1 -maxdepth 1 ! -name .git -exec sudo -n chown -h -R -P --from='.escapeshellarg($worker).' "$(id -un):$(id -gn)" -- {} +'."\n}\n";
    }

    public static function bashPreamble(?string $checkout = null): string
    {
        $trust = $checkout === null ? '' : ' -c '.escapeshellarg('safe.directory='.$checkout);

        return 'git() { command git -c core.hooksPath=/dev/null -c core.fsmonitor=false'.$trust.' "$@"; }'."\n";
    }
}
