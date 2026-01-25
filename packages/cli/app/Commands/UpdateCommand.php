<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\Updater\GitHubReleasesStrategy;
use Humbug\SelfUpdate\Updater as PharUpdater;
use LaravelZero\Framework\Commands\Command;

class UpdateCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'update
        {--json : Output as JSON}';

    protected $description = 'Check for available Orbit updates';

    public function handle(): int
    {
        $currentVersion = config('app.version');

        // Create strategy to check for updates
        $strategy = new GitHubReleasesStrategy;
        $strategy->setPackageName('hardimpactdev/orbit-cli');
        $strategy->setCurrentLocalVersion($currentVersion);
        $strategy->setPharName('orbit.phar');

        // Create a dummy updater to fetch version info
        $updater = new PharUpdater(null, false);
        $updater->setStrategyObject($strategy);

        $remoteVersion = $strategy->getCurrentRemoteVersion($updater);

        if (empty($remoteVersion)) {
            return $this->handleError(
                'Failed to fetch release information from GitHub.',
                ExitCode::GeneralError
            );
        }

        // Compare versions
        $current = ltrim((string) $currentVersion, 'v');
        $remote = ltrim($remoteVersion, 'v');
        $isUpToDate = $currentVersion !== '@version@' && version_compare($current, $remote, '>=');

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'current_version' => $currentVersion,
                'latest_version' => "v{$remoteVersion}",
                'up_to_date' => $isUpToDate,
                'update_available' => ! $isUpToDate,
            ]);
        }

        $this->info("Current version: {$currentVersion}");
        $this->info("Latest version:  v{$remoteVersion}");
        $this->newLine();

        if ($isUpToDate) {
            $this->info('You are running the latest version.');
        } else {
            $this->warn("A new version is available: v{$remoteVersion}");
            $this->info('Run `orbit upgrade` to install the update.');
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
