<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class LocalUnattendedUpgradesProbe
{
    private const string AUTO_PATH = '/etc/apt/apt.conf.d/20auto-upgrades';

    private const string UNATTENDED_PATH = '/etc/apt/apt.conf.d/50unattended-upgrades';

    private const string LOG_PATH = '/var/log/unattended-upgrades/unattended-upgrades.log';

    private const string REBOOT_REQUIRED_PATH = '/var/run/reboot-required';

    private const string REBOOT_REQUIRED_PACKAGES_PATH = '/var/run/reboot-required.pkgs';

    /**
     * @return array<string, mixed>
     */
    public function check(mixed $autoHash, mixed $unattendedHash): array
    {
        $autoHash = $this->hash($autoHash, 'autoHash');
        $unattendedHash = $this->hash($unattendedHash, 'unattendedHash');
        $installed = $this->isInstalled();
        $autoExists = is_file(self::AUTO_PATH);
        $unattendedExists = is_file(self::UNATTENDED_PATH);
        $autoHashOk = $autoExists && hash_file('sha256', self::AUTO_PATH) === $autoHash;
        $unattendedHashOk = $unattendedExists && hash_file('sha256', self::UNATTENDED_PATH) === $unattendedHash;
        $configReady = $autoExists && $unattendedExists && $autoHashOk && $unattendedHashOk;

        return [
            'installed' => $installed,
            'auto_exists' => $autoExists,
            'unattended_exists' => $unattendedExists,
            'auto_hash_ok' => $autoHashOk,
            'unattended_hash_ok' => $unattendedHashOk,
            'dry_run_exit' => $installed && $configReady ? $this->dryRunExit() : null,
            'last_run_status' => $this->lastRunStatus(),
            'reboot_required' => is_file(self::REBOOT_REQUIRED_PATH),
            'reboot_required_packages' => $this->rebootRequiredPackages(),
        ];
    }

    private function hash(mixed $hash, string $field): string
    {
        if (is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/i', $hash) === 1) {
            return strtolower($hash);
        }

        throw new LocalUnattendedUpgradesProbeFailure(
            errorCode: 'validation_failed',
            message: 'Unattended-upgrades expected config hash is invalid.',
            meta: ['field' => $field],
        );
    }

    private function isInstalled(): bool
    {
        $result = $this->run(['dpkg-query', '-W', '-f=${Status}', 'unattended-upgrades'], timeout: 10);

        return $result->isSuccessful() && str_contains($result->getOutput(), 'install ok installed');
    }

    private function dryRunExit(): ?int
    {
        $result = $this->run(['sudo', 'unattended-upgrade', '--dry-run'], timeout: 120);

        return $result->getExitCode();
    }

    private function lastRunStatus(): string
    {
        if (! is_file(self::LOG_PATH)) {
            return 'unknown';
        }

        $lines = file(self::LOG_PATH, FILE_IGNORE_NEW_LINES);

        if (! is_array($lines)) {
            return 'unknown';
        }

        $tail = implode("\n", array_slice($lines, -80));

        if (preg_match('/error|failed|traceback|exception/i', $tail) === 1) {
            return 'failed';
        }

        return trim($tail) === '' ? 'unknown' : 'completed';
    }

    /**
     * @return list<string>
     */
    private function rebootRequiredPackages(): array
    {
        if (! is_file(self::REBOOT_REQUIRED_PACKAGES_PATH)) {
            return [];
        }

        $lines = file(self::REBOOT_REQUIRED_PACKAGES_PATH, FILE_IGNORE_NEW_LINES);

        if (! is_array($lines)) {
            return [];
        }

        return array_values(array_filter(array_map(
            trim(...),
            $lines,
        )));
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, int $timeout): Process
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }
}
