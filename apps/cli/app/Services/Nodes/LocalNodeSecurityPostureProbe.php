<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use Closure;
use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class LocalNodeSecurityPostureProbe
{
    private const string SSHD_CONFIG = '/etc/ssh/sshd_config.d/99-orbit-hardening.conf';

    private const string SYSCTL_CONFIG = '/etc/sysctl.d/60-orbit.conf';

    private ManagedHomeAclAssessor $homeAclAssessor;

    /**
     * @param  (Closure(string): bool)|null  $directoryExists
     * @param  (Closure(string): (int|false))|null  $filePermissions
     * @param  (Closure(list<string>, int): Process)|null  $runner
     */
    public function __construct(
        private ?Closure $directoryExists = null,
        private ?Closure $filePermissions = null,
        private ?Closure $runner = null,
        ?ManagedHomeAclAssessor $homeAclAssessor = null,
    ) {
        $this->homeAclAssessor = $homeAclAssessor ?? new ManagedHomeAclAssessor;
    }

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
        return $this->run(['id', '-u', $managedUser], timeout: 10)->isSuccessful();
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
        if (! $this->pathIsDirectory($managedHome)) {
            return false;
        }

        $permissions = $this->pathPermissions($managedHome);

        if ($permissions === false) {
            return false;
        }

        $mode = $permissions & 0o777;

        // Owner must retain full home access.
        if (($mode & 0o700) !== 0o700) {
            return false;
        }

        // Pure owner-only mode remains the default hardened baseline.
        if ($mode === 0o700) {
            return true;
        }

        // With POSIX ACLs the group class encodes the ACL mask. The only
        // accepted non-0700 traditional mode is 0710 (mask::--x) from the
        // managed Agent consumer traversal entry u:agent:--x.
        if ($mode !== 0o710) {
            return false;
        }

        return $this->homeHasOnlyManagedAgentTraversalAcl($managedHome);
    }

    private function homeHasOnlyManagedAgentTraversalAcl(string $managedHome): bool
    {
        $process = $this->run(['getfacl', '-p', $managedHome], timeout: 10);

        if (! $process->isSuccessful()) {
            return false;
        }

        return $this->homeAclAssessor->isManagedAgentTraversalOnly($process->getOutput());
    }

    private function pathIsDirectory(string $path): bool
    {
        if ($this->directoryExists instanceof Closure) {
            return ($this->directoryExists)($path);
        }

        return is_dir($path);
    }

    private function pathPermissions(string $path): int|false
    {
        if ($this->filePermissions instanceof Closure) {
            $permissions = ($this->filePermissions)($path);

            return is_int($permissions) ? $permissions : false;
        }

        return fileperms($path);
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, int $timeout): Process
    {
        if ($this->runner instanceof Closure) {
            return ($this->runner)($command, $timeout);
        }

        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }
}
