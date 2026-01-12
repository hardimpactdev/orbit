<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class HorizonStatusCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'horizon:status {--json : Output as JSON}';

    protected $description = 'Check if Horizon is running';

    public function handle(ConfigManager $configManager): int
    {
        $webAppPath = $configManager->getWebAppPath();

        if (! is_dir($webAppPath)) {
            if ($this->wantsJson()) {
                return $this->outputJsonError('Web app not installed', [
                    'running' => false,
                    'installed' => false,
                ]);
            }
            $this->error('Launchpad web app is not installed.');
            $this->line('Run: launchpad init');

            return self::FAILURE;
        }

        $result = Process::path($webAppPath)->run('php artisan horizon:status');
        $isRunning = str_contains($result->output(), 'Horizon is running');

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'running' => $isRunning,
                'installed' => true,
                'output' => trim($result->output()),
            ]);
        }

        if ($isRunning) {
            $this->info('Horizon is running.');
        } else {
            $this->warn('Horizon is not running.');
        }

        return $isRunning ? self::SUCCESS : self::FAILURE;
    }
}
