<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\RemoteShell;
use App\Models\Node;

interface SecurityInstaller
{
    // @orbit-ssh-lane provisioning-ssh
    public function installFor(Node $node, ?RemoteShell $provisioningShell = null): InstallReport;
}
