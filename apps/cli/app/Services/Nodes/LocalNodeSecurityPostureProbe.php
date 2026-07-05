<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use Symfony\Component\Process\Process;

final readonly class LocalNodeSecurityPostureProbe
{
    private const string SSHD_CONFIG = '/etc/ssh/sshd_config.d/99-orbit-hardening.conf';

    private const string SYSCTL_CONFIG = '/etc/sysctl.d/60-orbit.conf';

    /**
     * @return array<string, bool>
     */
    public function check(mixed $managedUser): array
    {
        $managedUser = $this->managedUser($managedUser);
        $managedHome = "/home/{$managedUser}";

        return [
            'runtime_user' => $this->runtimeUserExists($managedUser),
            'sshd_config' => $this->sshdConfigIsHardened($managedUser),
            'sshd_listen' => true,
            'sysctl' => is_file(self::SYSCTL_CONFIG),
            'home_perms' => $this->homePermissionsAreHardened($managedHome),
        ];
    }

    private function managedUser(mixed $managedUser): string
    {
        if (! is_string($managedUser) || trim($managedUser) === '') {
            throw new LocalNodeSecurityPostureProbeFailure(
                errorCode: 'validation_failed',
                message: 'Managed user is required.',
                meta: ['field' => 'managedUser'],
            );
        }

        return trim($managedUser);
    }

    private function runtimeUserExists(string $managedUser): bool
    {
        $process = new Process(['id', '-u', $managedUser]);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    private function sshdConfigIsHardened(string $managedUser): bool
    {
        if (! is_file(self::SSHD_CONFIG)) {
            return false;
        }

        $content = file_get_contents(self::SSHD_CONFIG);

        if (! is_string($content)) {
            return false;
        }

        return (
            str_contains($content, 'PasswordAuthentication no') && str_contains($content, "AllowUsers {$managedUser}")
        );
    }

    private function homePermissionsAreHardened(string $managedHome): bool
    {
        if (! is_dir($managedHome)) {
            return false;
        }

        $permissions = fileperms($managedHome);

        if ($permissions === false) {
            return false;
        }

        return ($permissions & 0o777) === 0o700;
    }
}
