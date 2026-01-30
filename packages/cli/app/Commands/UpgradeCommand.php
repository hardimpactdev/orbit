<?php
declare(strict_types=1);

namespace App\Commands;

use App\Actions\Upgrade\UpdateWebApp;
use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\DockerManager;
use LaravelZero\Framework\Commands\Command;

class UpgradeCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'upgrade
        {--check : Only check for updates without installing}
        {--json : Output as JSON}';

    protected $description = 'Upgrade Orbit to the latest version';

    private const GITHUB_API_URL = 'https://api.github.com/repos/hardimpactdev/orbit-cli/releases/latest';

    public function handle(): int
    {
        $currentVersion = config('app.version');
        $pharPath = \Phar::running(false);

        // Check if running as PHAR
        if (empty($pharPath) && ! $this->option('check')) {
            return $this->handleError(
                'Upgrade is only available when running as a compiled PHAR binary.',
                ExitCode::GeneralError
            );
        }

        // Fetch latest release info
        $release = $this->fetchLatestRelease();
        if ($release === null) {
            return $this->handleError(
                'Failed to fetch release information from GitHub.',
                ExitCode::GeneralError
            );
        }

        $latestVersion = $release['tag_name'];
        $isUpToDate = $this->isUpToDate($currentVersion, $latestVersion);

        // Check-only mode
        if ($this->option('check')) {
            return $this->handleCheckResult($currentVersion, $latestVersion, $isUpToDate);
        }

        // Already up to date
        if ($isUpToDate) {
            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'action' => 'upgrade',
                    'current_version' => $currentVersion,
                    'latest_version' => $latestVersion,
                    'upgraded' => false,
                    'message' => 'Already up to date.',
                ]);
            }

            $this->info("You are already running the latest version ({$latestVersion}).");

            return self::SUCCESS;
        }

        // Find the PHAR download URL
        $downloadUrl = $this->findPharDownloadUrl($release);
        if ($downloadUrl === null) {
            return $this->handleError(
                'Could not find PHAR download URL in the release.',
                ExitCode::GeneralError
            );
        }

        // Download and install
        if (! $this->wantsJson()) {
            $this->info("Upgrading from {$currentVersion} to {$latestVersion}...");
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'orbit_');
        if ($tempFile === false) {
            return $this->handleError(
                'Failed to create temporary file.',
                ExitCode::GeneralError
            );
        }

        try {
            // Download the new version
            if (! $this->downloadFile($downloadUrl, $tempFile)) {
                return $this->handleError(
                    'Failed to download the new version.',
                    ExitCode::GeneralError
                );
            }

            // Verify it's a valid PHAR
            if (! $this->isValidPhar($tempFile)) {
                return $this->handleError(
                    'Downloaded file is not a valid PHAR.',
                    ExitCode::GeneralError
                );
            }

            // CRITICAL: We cannot replace the PHAR while this process is running.
            // PHP's shutdown handlers will try to autoload classes from the NEW phar
            // using OLD memory offsets, causing garbage output.
            //
            // Solution: Write a self-deleting shell script that replaces the PHAR
            // after we exit. This avoids "Killed" messages on Linux.

            // Make the new file executable before the move
            @chmod($tempFile, 0755);

            // Create backup
            @copy($pharPath, $pharPath.'.bak');

            // Create a self-deleting upgrade script
            $upgradeScript = sys_get_temp_dir().'/orbit-upgrade-'.getmypid().'.sh';
            $scriptContent = sprintf(
                "#!/bin/sh\n".
                "sleep 0.2\n".
                "mv %s %s\n".
                "rm -f %s\n".
                "rm -f \$0\n",  // Self-delete
                escapeshellarg($tempFile),
                escapeshellarg($pharPath),
                escapeshellarg($pharPath.'.bak')
            );

            file_put_contents($upgradeScript, $scriptContent);
            chmod($upgradeScript, 0755);

            // Use different approaches for Linux vs macOS
            if (PHP_OS_FAMILY === 'Darwin') {
                // macOS: current approach works fine
                exec(sprintf('nohup %s > /dev/null 2>&1 &', escapeshellarg($upgradeScript)));
            } else {
                // Linux: use setsid to fully detach from terminal
                exec(sprintf('setsid %s > /dev/null 2>&1 < /dev/null &', escapeshellarg($upgradeScript)));
            }

            // After upgrade, update web app and restart services
            if (! $this->wantsJson()) {
                $this->info("Successfully upgraded to {$latestVersion}!");
                $this->newLine();

                // Update companion web app
                $this->info('Updating companion web app...');
                $updateWebApp = app(UpdateWebApp::class);
                if (! $updateWebApp->handle()) {
                    $this->warn('Failed to update web app. You may need to run `orbit install` manually.');
                } else {
                    $this->info('✓ Web app updated');
                }

                // Restart services
                $this->info('Restarting services...');
                try {
                    $dockerManager = app(DockerManager::class);
                    $dockerManager->stopAll();
                    $dockerManager->startAll();
                    $this->info('✓ Services restarted');
                } catch (\Exception) {
                    $this->warn('Failed to restart some services. Run `orbit restart` to try again.');
                }

                $this->newLine();
                $this->info('Upgrade complete!');
            }

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'action' => 'upgrade',
                    'previous_version' => $currentVersion,
                    'new_version' => $latestVersion,
                    'upgraded' => true,
                ]);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Clean up temp file on error
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLatestRelease(): ?array
    {
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: orbit-cli\r\n",
                'timeout' => 30,
            ],
        ]);

        $response = @file_get_contents(self::GITHUB_API_URL, false, $context);
        if ($response === false) {
            return null;
        }

        /** @var array<string, mixed>|null */
        $data = json_decode($response, true);

        return is_array($data) ? $data : null;
    }

    private function isUpToDate(string $currentVersion, string $latestVersion): bool
    {
        // Remove 'v' prefix for comparison
        $current = ltrim($currentVersion, 'v');
        $latest = ltrim($latestVersion, 'v');

        // Handle @version@ placeholder (development mode)
        if ($current === '@version@') {
            return false;
        }

        return version_compare($current, $latest, '>=');
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function findPharDownloadUrl(array $release): ?string
    {
        /** @var array<int, array<string, mixed>> $assets */
        $assets = $release['assets'] ?? [];

        foreach ($assets as $asset) {
            $name = $asset['name'] ?? '';
            if ($name === 'orbit.phar') {
                return $asset['browser_download_url'] ?? null;
            }
        }

        return null;
    }

    private function downloadFile(string $url, string $destination): bool
    {
        // Use curl to stream directly to file (avoids memory exhaustion with large PHARs)
        $command = sprintf(
            'curl -fSL --max-time 300 -o %s %s 2>/dev/null',
            escapeshellarg($destination),
            escapeshellarg($url)
        );

        $result = null;
        $output = null;
        exec($command, $output, $result);

        return $result === 0 && file_exists($destination) && filesize($destination) > 0;
    }

    private function isValidPhar(string $path): bool
    {
        // Read the first 1KB to check for phar signature
        $content = @file_get_contents($path, false, null, 0, 1024);
        if ($content === false) {
            return false;
        }

        // Check for PHP shebang and phar indicators
        if (! str_contains($content, '<?php')) {
            return false;
        }

        // Check for __HALT_COMPILER which is required in all phars
        // We need to check the full file for this
        $fullContent = @file_get_contents($path);
        if ($fullContent === false) {
            return false;
        }

        return str_contains($fullContent, '__HALT_COMPILER()');
    }

    private function handleCheckResult(string $currentVersion, string $latestVersion, bool $isUpToDate): int
    {
        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'action' => 'check',
                'current_version' => $currentVersion,
                'latest_version' => $latestVersion,
                'up_to_date' => $isUpToDate,
                'update_available' => ! $isUpToDate,
            ]);
        }

        $this->info("Current version: {$currentVersion}");
        $this->info("Latest version:  {$latestVersion}");

        if ($isUpToDate) {
            $this->info('You are up to date!');
        } else {
            $this->warn('An update is available. Run `orbit upgrade` to install.');
        }

        return self::SUCCESS;
    }

    private function handleError(string $message, ExitCode $exitCode): int
    {
        if ($this->wantsJson()) {
            return $this->outputJsonError($message, $exitCode->value);
        }

        $this->error($message);

        return $exitCode->value;
    }
}
