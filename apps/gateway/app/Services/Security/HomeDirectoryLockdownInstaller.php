<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\RemoteShell;
use App\Models\Node;
use InvalidArgumentException;

/**
 * Hardens the managed Orbit runtime user's home directory to mode 0700.
 * Path and ownership are derived only from the node record — never from
 * arbitrary caller path input.
 */
final class HomeDirectoryLockdownInstaller implements SecurityInstaller
{
    public function installFor(Node $node, ?RemoteShell $provisioningShell = null): InstallReport
    {
        $managedUser = $this->managedUser($node);
        $result = app(SecurityInstallerTransport::class)->run(
            $node,
            $provisioningShell,
            $this->scriptFor($managedUser),
            60,
        );

        return new InstallReport(
            successful: $result->successful(),
            summary: $result->successful()
                ? "Locked down /home/{$managedUser} permissions."
                : "Failed to lock down /home/{$managedUser} permissions.",
            details: [
                'exit_code' => $result->exitCode,
                'managed_user' => $managedUser,
                'home' => "/home/{$managedUser}",
            ],
        );
    }

    /**
     * Bake-time convenience for the historical orbit runtime user.
     */
    public function script(): string
    {
        return $this->scriptFor('orbit');
    }

    /**
     * @throws InvalidArgumentException when the managed user is unsafe
     */
    public function scriptFor(string $managedUser): string
    {
        $user = $this->assertManagedUser($managedUser);
        $home = "/home/{$user}";

        $base = <<<SH
            set -euo pipefail
            MANAGED_USER='{$user}'
            MANAGED_HOME='{$home}'
            # Only the fixed managed home may be repaired — refuse unexpected paths.
            case "\${MANAGED_HOME}" in
              /home/*) ;;
              *) echo "refusing non-home path" >&2; exit 1 ;;
            esac
            id -u "\${MANAGED_USER}" >/dev/null
            # Require the path to be the managed user's home (passwd), not an
            # arbitrary directory under /home.
            EXPECTED_HOME="\$(getent passwd "\${MANAGED_USER}" | cut -d: -f6)"
            if [ "\${EXPECTED_HOME}" != "\${MANAGED_HOME}" ]; then
              echo "managed home mismatch" >&2
              exit 1
            fi
            if [ ! -d "\${MANAGED_HOME}" ]; then
              echo "managed home missing" >&2
              exit 1
            fi
            OWNER="\$(stat -c '%U' "\${MANAGED_HOME}")"
            if [ "\${OWNER}" != "\${MANAGED_USER}" ]; then
              echo "managed home owner mismatch" >&2
              exit 1
            fi
            sudo chmod 0700 "\${MANAGED_HOME}"
            SH;

        if ($user !== 'orbit') {
            return $base;
        }

        return $base.<<<'SH'

            # Orbit bake-time directories used by the default runtime user.
            sudo install -d -m 0700 -o orbit -g orbit /home/orbit
            sudo install -d -m 0700 -o orbit -g orbit /home/orbit/.ssh
            sudo install -d -m 0755 -o orbit -g orbit /home/orbit/.config /home/orbit/.config/orbit /home/orbit/.config/orbit/logs /home/orbit/.config/orbit/php
            sudo chmod 0700 /home/orbit /home/orbit/.ssh
            sudo chown orbit:orbit /home/orbit /home/orbit/.ssh /home/orbit/.config /home/orbit/.config/orbit /home/orbit/.config/orbit/logs /home/orbit/.config/orbit/php
            if [ -f /home/orbit/.ssh/authorized_keys ]; then
              sudo chmod 0600 /home/orbit/.ssh/authorized_keys
              sudo chown orbit:orbit /home/orbit/.ssh/authorized_keys
            fi
            SH;
    }

    private function managedUser(Node $node): string
    {
        return $this->assertManagedUser(trim((string) $node->user));
    }

    private function assertManagedUser(string $managedUser): string
    {
        if ($managedUser === '' || ! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $managedUser)) {
            throw new InvalidArgumentException('Managed runtime user is missing or unsafe for home lockdown.');
        }

        return $managedUser;
    }
}
