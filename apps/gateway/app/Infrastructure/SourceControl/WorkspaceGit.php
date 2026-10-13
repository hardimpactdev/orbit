<?php

declare(strict_types=1);

namespace App\Infrastructure\SourceControl;

/** Suppress checkout-controlled hooks and fsmonitor for Gateway shell reads and mutations. */
final readonly class WorkspaceGit
{
    public static function bashPreamble(): string
    {
        return 'git() { command git -c core.hooksPath=/dev/null -c core.fsmonitor=false "$@"; }'."\n";
    }
}
