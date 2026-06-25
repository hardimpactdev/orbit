<?php

declare(strict_types=1);

namespace App\Commands\Process;

final class ProcessEditCommand extends ProcessUpdateCommand
{
    #[\Override]
    protected $signature = 'process:edit
        {name? : Existing process name}
        {--name= : New process name}
        {--node= : Owning node name}
        {--app= : Parent app slug}
        {--workspace= : Workspace name}
        {--command= : New command}
        {--restart-policy= : Restart policy (never|on_failure|always)}
        {--crash-notification= : Crash notification policy (none|agent_ide)}
        {--runtime= : Process runtime (docker|docker-swarm|systemd)}
        {--restart : Restart affected runtime units after update}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Compatibility alias for process:update.';

    #[\Override]
    protected $hidden = true;
}
