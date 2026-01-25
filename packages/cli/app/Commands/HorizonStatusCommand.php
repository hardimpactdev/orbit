<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\HorizonManager;
use LaravelZero\Framework\Commands\Command;

class HorizonStatusCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'horizon:status {--json : Output as JSON}';

    protected $description = 'Check Horizon status';

    public function handle(HorizonManager $horizonManager): int
    {
        try {
            $isInstalled = $horizonManager->isInstalled();
            $isRunning = $isInstalled ? $horizonManager->isRunning() : false;

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'installed' => $isInstalled,
                    'running' => $isRunning,
                ]);
            }

            if (! $isInstalled) {
                $this->warn('Horizon service is not installed');
                $this->info('Run: orbit horizon:install');

                return self::SUCCESS;
            }

            if ($isRunning) {
                $this->info('Horizon is running');
            } else {
                $this->warn('Horizon is not running');
                $this->info('Run: orbit horizon:start');
            }

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            if ($this->wantsJson()) {
                return $this->outputJsonError($e->getMessage());
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
