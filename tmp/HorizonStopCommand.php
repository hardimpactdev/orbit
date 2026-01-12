<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class HorizonStopCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'horizon:stop {--json : Output as JSON}';

    protected $description = 'Stop Horizon queue worker';

    public function handle(ConfigManager $configManager): int
    {
        $webAppPath = $configManager->getWebAppPath();

        if (! is_dir($webAppPath)) {
            if ($this->wantsJson()) {
                return $this->outputJsonError('Web app not installed');
            }
            $this->error('Launchpad web app is not installed.');

            return self::FAILURE;
        }

        // Check if running
        $statusResult = Process::path($webAppPath)->run('php artisan horizon:status');
        if (! str_contains($statusResult->output(), 'Horizon is running')) {
            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'stopped' => false,
                    'was_running' => false,
                ]);
            }
            $this->info('Horizon is not running.');

            return self::SUCCESS;
        }

        // Terminate Horizon gracefully
        $result = Process::path($webAppPath)->run('php artisan horizon:terminate');

        // Wait for it to stop
        sleep(2);

        // Verify
        $verifyResult = Process::path($webAppPath)->run('php artisan horizon:status');
        $isStopped = ! str_contains($verifyResult->output(), 'Horizon is running');

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'stopped' => $isStopped,
                'was_running' => true,
            ]);
        }

        if ($isStopped) {
            $this->info('Horizon stopped successfully.');

            return self::SUCCESS;
        }

        $this->warn('Horizon may still be stopping...');

        return self::SUCCESS;
    }
}
