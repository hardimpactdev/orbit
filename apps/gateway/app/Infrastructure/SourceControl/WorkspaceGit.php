<?php

declare(strict_types=1);

namespace App\Infrastructure\SourceControl;

use App\Models\Instance;

/** Suppress checkout-controlled hooks and fsmonitor for Gateway shell reads and mutations. */
final readonly class WorkspaceGit
{
    /**
     * The Git directory that holds a development Instance's objects and refs: the seed's for a worktree of a seed,
     * otherwise the checkout's own. The Gateway derives it from its records, never from `.git/commondir` or a `.git`
     * file in the checkout.
     */
    public static function commonDirectory(Instance $instance): string
    {
        return rtrim($instance->seed_repository ?? $instance->checkout_path, '/').'/.git';
    }

    public static function bashPreamble(?string $checkout = null): string
    {
        $trust = $checkout === null ? '' : ' -c '.escapeshellarg('safe.directory='.$checkout);

        return 'git() { command git -c core.hooksPath=/dev/null -c core.fsmonitor=false'.$trust.' "$@"; }'."\n";
    }
}
