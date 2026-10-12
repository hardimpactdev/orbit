<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\NodeRole;

/**
 * Removes the host network boundary of the retired Incus sandbox controller from an `app-dev` Node: its boot
 * service, the Incus dependency on it, its root helper, its configuration, and its lock directory. It drops
 * the Incus dependency and reloads systemd before it disables the boot service, and it stops nothing, so
 * Incus keeps running. A Node without these files is left unchanged.
 */
final readonly class SandboxResidueFootprintArtifact implements NodeFootprintArtifact
{
    public const string Script = <<<'BASH'
        root=${1%/}
        dropin=$root/etc/systemd/system/incus.service.d/orbit-sandbox-network.conf
        unit=$root/etc/systemd/system/orbit-sandbox-host-network.service
        wants=$root/etc/systemd/system/multi-user.target.wants/orbit-sandbox-host-network.service
        changed=
        if [ -e "$dropin" ] || [ -L "$dropin" ]; then
            rm -f -- "$dropin"
            rmdir --ignore-fail-on-non-empty -- "${dropin%/*}"
            systemctl daemon-reload
            changed=1
        fi
        if [ -e "$unit" ] || [ -L "$unit" ] || [ -L "$wants" ]; then
            systemctl disable orbit-sandbox-host-network.service >/dev/null 2>&1 || true
            rm -f -- "$wants" "$unit"
            systemctl daemon-reload
            changed=1
        fi
        for path in "$root/usr/local/libexec/orbit-sandbox-network" "$root/etc/orbit/sandbox-network.json"; do
            if [ -e "$path" ] || [ -L "$path" ]; then
                rm -f -- "$path"
                changed=1
            fi
        done
        if [ -e "$root/var/lib/orbit-sandbox-network" ]; then
            rm -rf -- "$root/var/lib/orbit-sandbox-network"
            changed=1
        fi
        if [ -n "$changed" ]; then
            printf 'changed\n'
        fi
        BASH;

    public function __construct(private NodeShell $shell) {}

    public function name(): string
    {
        return 'sandbox-residue';
    }

    public function applies(Node $node): bool
    {
        return $node->platform === 'linux' && $node->roles->contains(
            static fn (NodeRole $role): bool => $role->role === RoleName::AppDev && $role->status === LifecycleStatus::Active,
        );
    }

    public function digest(Node $node): string
    {
        return hash('sha256', self::Script);
    }

    public function apply(Node $node): bool
    {
        $result = $this->shell->run($node, new RemoteCommand(
            arguments: ['sudo', 'bash', '-seu', '--', '/'],
            input: self::Script,
        ));

        if (! $result->succeeded()) {
            throw new ResourceOperationException('node.footprint_sandbox_residue_failed', 'The retired sandbox host files could not be removed: '.trim($result->stderr), 502);
        }

        return str_contains($result->stdout, 'changed');
    }
}
