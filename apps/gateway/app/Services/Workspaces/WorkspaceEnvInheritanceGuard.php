<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

final class WorkspaceEnvInheritanceGuard
{
    public function consumesParentEnv(string $command): bool
    {
        $unquotedCommand = str_replace(['"', "'"], '', $command);

        return (
            preg_match(
                '/(?:\\$ORBIT_APP_PATH|\\$\\{ORBIT_APP_PATH\\})\\/\\.env(?![A-Za-z0-9_.-])/',
                $unquotedCommand,
            ) === 1
        );
    }
}
