<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\HorizonManager;
use LaravelZero\Framework\Commands\Command;

class HorizonUninstallCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'horizon:uninstall {--json : Output as JSON}';

    protected $description = 'Uninstall Horizon system service';

    public function handle(HorizonManager $horizonManager): int
    {
        try {
            if (! $horizonManager->isInstalled()) {
                if ($this->wantsJson()) {
                    return $this->outputJsonSuccess([
                        'uninstalled' => true,
                        'was_installed' => false,
                    ]);
                }

                $this->info('Horizon service is not installed.');

                return self::SUCCESS;
            }

            if (! $this->wantsJson()) {
                $this->info('Uninstalling Horizon system service...');
            }

            $result = $horizonManager->uninstall();

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'uninstalled' => $result,
                    'was_installed' => true,
                ]);
            }

            if ($result) {
                $this->info('Horizon service uninstalled successfully!');

                return self::SUCCESS;
            }

            $this->error('Failed to uninstall Horizon service');

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
