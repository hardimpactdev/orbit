<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\PhpManager;
use App\Services\PhpSettingsService;
use LaravelZero\Framework\Commands\Command;

class PhpConfigCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'php:config
        {version? : The PHP version to configure (default: latest installed)}
        {--get : Get current settings}
        {--upload-max-filesize= : Set upload_max_filesize}
        {--post-max-size= : Set post_max_size}
        {--memory-limit= : Set memory_limit}
        {--max-execution-time= : Set max_execution_time}
        {--max-children= : Set pm.max_children}
        {--start-servers= : Set pm.start_servers}
        {--min-spare-servers= : Set pm.min_spare_servers}
        {--max-spare-servers= : Set pm.max_spare_servers}
        {--json : Output as JSON}';

    protected $description = 'Get or set PHP configuration settings';

    public function handle(PhpManager $phpManager, PhpSettingsService $settingsService): int
    {
        $version = $this->argument('version');

        // If no version specified, use latest installed
        if (! $version) {
            $installed = $phpManager->getInstalledVersions();
            if (empty($installed)) {
                if ($this->wantsJson()) {
                    return $this->outputJsonError('No PHP versions installed.', ExitCode::InvalidArguments->value);
                }
                $this->error('No PHP versions installed.');

                return ExitCode::InvalidArguments->value;
            }
            // Sort and get latest
            usort($installed, version_compare(...));
            $version = end($installed);
        }

        // Check if getting settings or setting them
        $updates = [];
        $settingOptions = [
            'upload-max-filesize' => 'upload_max_filesize',
            'post-max-size' => 'post_max_size',
            'memory-limit' => 'memory_limit',
            'max-execution-time' => 'max_execution_time',
            'max-children' => 'max_children',
            'start-servers' => 'start_servers',
            'min-spare-servers' => 'min_spare_servers',
            'max-spare-servers' => 'max_spare_servers',
        ];

        foreach ($settingOptions as $optionName => $settingKey) {
            $value = $this->option($optionName);
            if ($value !== null) {
                $updates[$settingKey] = $value;
            }
        }

        // If no updates, show current settings
        if (empty($updates) || $this->option('get')) {
            return $this->showSettings($version, $settingsService);
        }

        // Apply updates
        return $this->updateSettings($version, $updates, $settingsService);
    }

    protected function showSettings(string $version, PhpSettingsService $settingsService): int
    {
        $settings = $settingsService->getSettings($version);

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'version' => $version,
                'settings' => $settings,
                'paths' => [
                    'php_ini' => $settingsService->getPhpIniPath($version),
                    'pool_config' => $settingsService->getPoolConfigPath($version),
                ],
            ]);
        }

        $this->info("PHP {$version} Settings:");
        $this->newLine();

        $this->line('<fg=yellow>php.ini:</>');
        $this->line("  upload_max_filesize: {$settings['upload_max_filesize']}");
        $this->line("  post_max_size: {$settings['post_max_size']}");
        $this->line("  memory_limit: {$settings['memory_limit']}");
        $this->line("  max_execution_time: {$settings['max_execution_time']}");

        $this->newLine();
        $this->line('<fg=yellow>php-fpm pool:</>');
        $this->line("  pm.max_children: {$settings['max_children']}");
        $this->line("  pm.start_servers: {$settings['start_servers']}");
        $this->line("  pm.min_spare_servers: {$settings['min_spare_servers']}");
        $this->line("  pm.max_spare_servers: {$settings['max_spare_servers']}");

        return self::SUCCESS;
    }

    protected function updateSettings(string $version, array $updates, PhpSettingsService $settingsService): int
    {
        if (! $this->wantsJson()) {
            $this->info("Updating PHP {$version} settings...");
        }

        $success = $settingsService->updateSettings($version, $updates);

        if (! $success) {
            if ($this->wantsJson()) {
                return $this->outputJsonError('Failed to update settings.', ExitCode::GeneralError->value);
            }
            $this->error('Failed to update settings.');

            return ExitCode::GeneralError->value;
        }

        // Get updated settings
        $settings = $settingsService->getSettings($version);

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'version' => $version,
                'updated' => array_keys($updates),
                'settings' => $settings,
            ]);
        }

        $this->info('Settings updated successfully. PHP-FPM restarted.');
        foreach ($updates as $key => $value) {
            $this->line("  {$key}: {$value}");
        }

        return self::SUCCESS;
    }
}
