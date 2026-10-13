<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Models\Node;

/**
 * Runs commands in the VM `$vm` of an Incus host with `incus exec` over the host's SSH connection. The VM needs no
 * address of its own: the host is the trusted path to it. Root commands run as the guest agent's root; user commands
 * go through `runuser`, as enrollment's programs run as the user over SSH.
 */
final readonly class IncusGuestShell implements TaskVmGuestShell
{
    public function __construct(
        private IncusHost $incus,
        private TaskVmHost $host,
        private Node $node,
        public string $vm,
    ) {}

    public function asUser(array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0, ?string $cwd = null): CommandResult
    {
        return $this->incus->run($this->host, $this->node, [
            'exec', '--cwd', $cwd ?? TaskVmCloudInit::Home, '--env', 'HOME='.TaskVmCloudInit::Home, '--env', 'USER='.TaskVmCloudInit::User,
            '--', $this->vm, 'runuser', '-u', TaskVmCloudInit::User, '--', ...$command,
        ], $input, $timeout);
    }

    public function asRoot(array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0): CommandResult
    {
        return $this->incus->run($this->host, $this->node, ['exec', '--', $this->vm, ...$command], $input, $timeout);
    }
}
