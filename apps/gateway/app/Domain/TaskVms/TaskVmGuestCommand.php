<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Infrastructure\TaskVms\TaskVmBakedPrograms;

/**
 * The fixed commands that Orbit runs inside a task VM before it is a Node, through {@see TaskVmProvider::exec()}.
 * Every provider runs the same argv: Incus as `root` with `incus exec` on the host, UpCloud as `orbit` over SSH with
 * the pinned host key. `TaskVmGuest` reads their output.
 */
enum TaskVmGuestCommand
{
    /** `cloud-init status`, which waits in the guest until cloud-init is done, at most {@see self::CloudInitWaitSeconds}. */
    case CloudInitStatus;

    /** The manifest of the programs that the VM's base image ran, or `absent` for an image without one. */
    case ImageManifest;

    /** How long one readiness check waits in the guest for cloud-init. A boot from the base image takes about 10 seconds. */
    public const int CloudInitWaitSeconds = 30;

    /** The output a provider keeps of one command; longer output comes back truncated. */
    public const int MaxOutputBytes = 1_048_576;

    /** @return non-empty-list<string> */
    public function argv(): array
    {
        return match ($this) {
            self::CloudInitStatus => ['timeout', (string) self::CloudInitWaitSeconds, 'cloud-init', 'status', '--wait', '--format=json'],
            self::ImageManifest => ['sh', '-c', 'if [ -e "$1" ]; then cat -- "$1"; else printf absent; fi', 'sh', TaskVmBakedPrograms::ManifestPath],
        };
    }

    /** How long the provider lets the command run, connection included. */
    public function timeoutSeconds(): int
    {
        return match ($this) {
            self::CloudInitStatus => self::CloudInitWaitSeconds + 15,
            self::ImageManifest => 30,
        };
    }
}
