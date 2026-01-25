<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\HorizonManager;
use LaravelZero\Framework\Commands\Command;

class HorizonStartCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'horizon:start {--json : Output as JSON}';

    protected $description = 'Start Horizon queue worker';

    public function handle(HorizonManager $horizonManager): int
    {
        try {
            if (! $horizonManager->isInstalled()) {
                if ($this->wantsJson()) {
                    return $this->outputJsonError('Horizon service is not installed. Run: orbit horizon:install');
                }

                $this->error('Horizon service is not installed. Run: orbit horizon:install');

                return self::FAILURE;
            }

            // Check if already running
            if ($horizonManager->isRunning()) {
                if ($this->wantsJson()) {
                    return $this->outputJsonSuccess([
                        'started' => false,
                        'already_running' => true,
                    ]);
                }
                $this->info('Horizon is already running.');

                return self::SUCCESS;
            }

            // Start the service
            $result = $horizonManager->start();

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'started' => $result,
                    'already_running' => false,
                ]);
            }

            if ($result) {
                $this->info('Horizon started successfully.');

                return self::SUCCESS;
            }

            $this->error('Horizon failed to start.');

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
