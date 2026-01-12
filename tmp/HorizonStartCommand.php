<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class HorizonStartCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'horizon:start {--json : Output as JSON}';

    protected $description = 'Start Horizon queue worker';

    public function handle(ConfigManager $configManager): int
    {
        $webAppPath = $configManager->getWebAppPath();

        if (! is_dir($webAppPath)) {
            if ($this->wantsJson()) {
                return $this->outputJsonError('Web app not installed');
            }
            $this->error('Launchpad web app is not installed.');
            $this->line('Run: launchpad init');

            return self::FAILURE;
        }

        // Check if already running
        $statusResult = Process::path($webAppPath)->run('php artisan horizon:status');
        if (str_contains($statusResult->output(), 'Horizon is running')) {
            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'started' => false,
                    'already_running' => true,
                ]);
            }
            $this->info('Horizon is already running.');

            return self::SUCCESS;
        }

        // Start Horizon in background
        $logPath = $configManager->getConfigPath().'/logs/horizon.log';
        $logDir = dirname($logPath);
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        Process::path($webAppPath)
            ->start("php artisan horizon >> {$logPath} 2>&1");

        // Wait and verify
        sleep(3);

        $verifyResult = Process::path($webAppPath)->run('php artisan horizon:status');
        $isRunning = str_contains($verifyResult->output(), 'Horizon is running');

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'started' => $isRunning,
                'already_running' => false,
            ]);
        }

        if ($isRunning) {
            $this->info('Horizon started successfully.');

            return self::SUCCESS;
        }

        $this->error('Horizon failed to start. Check logs at: '.$logPath);

        return self::FAILURE;
    }
}
