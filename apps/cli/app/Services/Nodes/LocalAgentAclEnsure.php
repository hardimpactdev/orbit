<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class LocalAgentAclEnsure
{
    /**
     * Canonical installed CLI/config paths that the agent role probe requires.
     *
     * @var list<string>
     */
    private const array REQUIRED_DIRECTORY_PATHS = [
        '/home/orbit',
        '/home/orbit/.config',
        '/home/orbit/.config/orbit',
        '/home/orbit/.local',
        '/home/orbit/.local/bin',
    ];

    /**
     * Optional legacy/source-checkout paths. Absent or ACL-unsupported entries
     * are best-effort only and must not fail installed-path repair.
     *
     * @var list<string>
     */
    private const array OPTIONAL_DIRECTORY_PATHS = [
        '/home/orbit/orbit',
        '/home/orbit/orbit/bin',
    ];

    /**
     * Required owner-config files. Missing or unreadable required paths must
     * fail closed at the config ACL stage.
     *
     * @var list<string>
     */
    private const array REQUIRED_CONFIG_PATHS = [
        '/home/orbit/.config/orbit/config.json',
    ];

    /**
     * Optional install metadata. Production installs should write it, but
     * legacy nodes may lack install.json; ACL repair must still complete for
     * config.json + binary when metadata is absent.
     *
     * @var list<string>
     */
    private const array OPTIONAL_CONFIG_PATHS = [
        '/home/orbit/.config/orbit/install.json',
    ];

    private const string REQUIRED_BINARY_PATH = '/home/orbit/.local/bin/orbit';

    private const string OPTIONAL_AGENT_BINARY_PATH = '/home/orbit/.local/bin/orbit-agent';

    /**
     * @param  (Closure(string): bool)|null  $directoryExists
     * @param  (Closure(string): bool)|null  $pathExists
     * @param  (Closure(list<string>, int): Process)|null  $runner
     */
    public function __construct(
        private ?Closure $directoryExists = null,
        private ?Closure $pathExists = null,
        private ?Closure $runner = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function ensure(): array
    {
        $probe = $this->run(['setfacl', '--version'], timeout: 10);
        $installedAcl = false;

        if (! $probe->isSuccessful()) {
            $update = $this->run(['sudo', 'apt-get', 'update'], timeout: 120);

            if (! $update->isSuccessful()) {
                throw new RuntimeException(
                    'Could not ensure Orbit agent runtime ACLs (stage=package_index).',
                );
            }

            $install = $this->run([
                'sudo',
                'env',
                'DEBIAN_FRONTEND=noninteractive',
                'apt-get',
                'install',
                '-y',
                'acl',
            ], timeout: 120);

            if (! $install->isSuccessful()) {
                throw new RuntimeException(
                    'Could not ensure Orbit agent runtime ACLs (stage=acl_package_install).',
                );
            }

            $installedAcl = true;
        }

        $directoryAcl = $this->run([
            'sudo',
            'setfacl',
            '-m',
            'u:agent:--x',
            ...self::REQUIRED_DIRECTORY_PATHS,
        ], timeout: 30);

        if (! $directoryAcl->isSuccessful()) {
            throw new RuntimeException(
                'Could not ensure Orbit agent runtime ACLs (stage=directory_acl).',
            );
        }

        [$optionalDirectoryPathsApplied, $optionalDirectoryPathsSkipped] = $this->applyOptionalDirectoryAcls();

        [$configAclExitCode, $optionalConfigPathsApplied, $optionalConfigPathsSkipped] = $this->applyConfigAcls();

        $binaryAcl = $this->run([
            'sudo',
            'setfacl',
            '-m',
            'u:agent:r-x',
            self::REQUIRED_BINARY_PATH,
        ], timeout: 30);

        if (! $binaryAcl->isSuccessful()) {
            throw new RuntimeException(
                'Could not ensure Orbit agent runtime ACLs (stage=binary_acl).',
            );
        }

        [$agentBinaryAclExitCode, $optionalAgentBinarySkipped] = $this->applyOptionalAgentBinaryAcl();

        return [
            'installed_acl' => $installedAcl,
            'directory_acl_exit_code' => $directoryAcl->getExitCode(),
            'config_acl_exit_code' => $configAclExitCode,
            'binary_acl_exit_code' => $binaryAcl->getExitCode(),
            'agent_binary_acl_exit_code' => $agentBinaryAclExitCode,
            'optional_directory_paths_applied' => $optionalDirectoryPathsApplied,
            'optional_directory_paths_skipped' => $optionalDirectoryPathsSkipped,
            'optional_config_paths_applied' => $optionalConfigPathsApplied,
            'optional_config_paths_skipped' => $optionalConfigPathsSkipped,
            'optional_agent_binary_skipped' => $optionalAgentBinarySkipped,
        ];
    }

    /**
     * @return array{0: int, 1: list<string>, 2: list<array{path: string, reason: string}>}
     */
    private function applyConfigAcls(): array
    {
        foreach (self::REQUIRED_CONFIG_PATHS as $path) {
            if (! $this->pathExists($path)) {
                throw new RuntimeException(
                    "Could not ensure Orbit agent runtime ACLs (stage=config_acl). Required path is missing: {$path}",
                );
            }
        }

        $configAcl = $this->run([
            'sudo',
            'setfacl',
            '-m',
            'u:agent:r--',
            ...self::REQUIRED_CONFIG_PATHS,
        ], timeout: 30);

        if (! $configAcl->isSuccessful()) {
            throw new RuntimeException(
                'Could not ensure Orbit agent runtime ACLs (stage=config_acl).',
            );
        }

        $optionalApplied = [];
        $optionalSkipped = [];

        foreach (self::OPTIONAL_CONFIG_PATHS as $path) {
            if (! $this->pathExists($path)) {
                $optionalSkipped[] = [
                    'path' => $path,
                    'reason' => 'absent',
                ];

                continue;
            }

            $optional = $this->run([
                'sudo',
                'setfacl',
                '-m',
                'u:agent:r--',
                $path,
            ], timeout: 30);

            if ($optional->isSuccessful()) {
                $optionalApplied[] = $path;

                continue;
            }

            $optionalSkipped[] = [
                'path' => $path,
                'reason' => 'acl_unsupported',
            ];
        }

        return [$configAcl->getExitCode() ?? 0, $optionalApplied, $optionalSkipped];
    }

    /**
     * @return array{0: list<string>, 1: list<array{path: string, reason: string}>}
     */
    private function applyOptionalDirectoryAcls(): array
    {
        $applied = [];
        $skipped = [];

        foreach (self::OPTIONAL_DIRECTORY_PATHS as $path) {
            if (! $this->directoryExists($path)) {
                $skipped[] = [
                    'path' => $path,
                    'reason' => 'absent',
                ];

                continue;
            }

            $optional = $this->run([
                'sudo',
                'setfacl',
                '-m',
                'u:agent:--x',
                $path,
            ], timeout: 30);

            if ($optional->isSuccessful()) {
                $applied[] = $path;

                continue;
            }

            // Present source/virtiofs mounts may not support ACL; non-fatal.
            $skipped[] = [
                'path' => $path,
                'reason' => 'acl_unsupported',
            ];
        }

        return [$applied, $skipped];
    }

    /**
     * @return array{0: int, 1: array{path: string, reason: string}|null}
     */
    private function applyOptionalAgentBinaryAcl(): array
    {
        if (! $this->pathExists(self::OPTIONAL_AGENT_BINARY_PATH)) {
            return [0, null];
        }

        $agentBinaryAcl = $this->run([
            'sudo',
            'setfacl',
            '-m',
            'u:agent:r-x',
            self::OPTIONAL_AGENT_BINARY_PATH,
        ], timeout: 30);

        if ($agentBinaryAcl->isSuccessful()) {
            return [$agentBinaryAcl->getExitCode() ?? 0, null];
        }

        return [
            $agentBinaryAcl->getExitCode() ?? 1,
            [
                'path' => self::OPTIONAL_AGENT_BINARY_PATH,
                'reason' => 'acl_unsupported',
            ],
        ];
    }

    private function directoryExists(string $path): bool
    {
        if ($this->directoryExists instanceof Closure) {
            return (bool) ($this->directoryExists)($path);
        }

        return is_dir($path);
    }

    private function pathExists(string $path): bool
    {
        if ($this->pathExists instanceof Closure) {
            return (bool) ($this->pathExists)($path);
        }

        return is_file($path) || is_link($path);
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
