<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\PhpManager;
use LaravelZero\Framework\Commands\Command;

class PhpCliCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'php-cli
        {version? : The PHP version to use (8.3, 8.4, 8.5)}
        {--json : Output as JSON}';

    protected $description = 'Set the default PHP CLI version';

    protected array $validVersions = ['8.3', '8.4', '8.5'];

    public function handle(PhpManager $phpManager): int
    {
        $platform = $phpManager->getAdapter();
        $version = $this->argument('version');

        // Show current version if no argument provided
        if (! $version) {
            $currentVersion = $platform->getDefaultPhpCli();

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'php_version' => $currentVersion,
                    'installed_versions' => $platform->getInstalledPhpVersions(),
                ]);
            }

            if ($currentVersion) {
                $this->info("Current PHP CLI version: {$currentVersion}");
            } else {
                $this->warn('Could not detect PHP CLI version');
            }

            $installed = $platform->getInstalledPhpVersions();
            if (! empty($installed)) {
                $this->line('Installed versions: '.implode(', ', $installed));
            }

            return self::SUCCESS;
        }

        // Validate version
        if (! in_array($version, $this->validVersions)) {
            $message = 'Invalid PHP version. Valid versions: '.implode(', ', $this->validVersions);
            if ($this->wantsJson()) {
                return $this->outputJsonError($message, ExitCode::InvalidArguments->value, [
                    'valid_versions' => $this->validVersions,
                ]);
            }
            $this->error($message);

            return ExitCode::InvalidArguments->value;
        }

        // Check if version is installed
        if (! $platform->isPhpInstalled($version)) {
            $message = "PHP {$version} is not installed. Run 'orbit setup --php-versions={$version}' to install it.";
            if ($this->wantsJson()) {
                return $this->outputJsonError($message, ExitCode::InvalidArguments->value);
            }
            $this->error($message);

            return ExitCode::InvalidArguments->value;
        }

        // Set the PHP CLI version
        $success = $platform->setDefaultPhpCli($version);

        if (! $success) {
            $message = "Failed to set PHP CLI to version {$version}";
            if ($this->wantsJson()) {
                return $this->outputJsonError($message, ExitCode::GeneralError->value);
            }
            $this->error($message);

            return ExitCode::GeneralError->value;
        }

        // Verify the change
        $newVersion = $platform->getDefaultPhpCli();

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'php_version' => $newVersion,
                'action' => 'set',
            ]);
        }

        $this->info("PHP CLI version set to {$version}");

        return self::SUCCESS;
    }
}
