<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\HorizonManager;
use LaravelZero\Framework\Commands\Command;

class HorizonRestartCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'horizon:restart {--json : Output as JSON}';

    protected $description = 'Restart Horizon queue worker';

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

            $result = $horizonManager->restart();

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'restarted' => $result,
                ]);
            }

            if ($result) {
                $this->info('Horizon restarted successfully.');

                return self::SUCCESS;
            }

            $this->error('Horizon failed to restart.');

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
