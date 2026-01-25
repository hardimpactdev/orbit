<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\HorizonManager;
use LaravelZero\Framework\Commands\Command;

class HorizonStopCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'horizon:stop {--json : Output as JSON}';

    protected $description = 'Stop Horizon queue worker';

    public function handle(HorizonManager $horizonManager): int
    {
        try {
            if (! $horizonManager->isInstalled()) {
                if ($this->wantsJson()) {
                    return $this->outputJsonError('Horizon service is not installed');
                }

                $this->info('Horizon service is not installed.');

                return self::SUCCESS;
            }

            // Check if not running
            if (! $horizonManager->isRunning()) {
                if ($this->wantsJson()) {
                    return $this->outputJsonSuccess([
                        'stopped' => true,
                        'was_running' => false,
                    ]);
                }
                $this->info('Horizon is not running.');

                return self::SUCCESS;
            }

            // Stop the service
            $result = $horizonManager->stop();

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'stopped' => $result,
                    'was_running' => true,
                ]);
            }

            if ($result) {
                $this->info('Horizon stopped successfully.');

                return self::SUCCESS;
            }

            $this->error('Horizon failed to stop.');

            return self::FAILURE;
        } catch (\RuntimeException $e) {
            if ($this->wantsJson()) {
                return $this->outputJsonError($e->getMessage());
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
