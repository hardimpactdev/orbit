<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\HorizonManager;
use LaravelZero\Framework\Commands\Command;

class HorizonInstallCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'horizon:install {--json : Output as JSON}';

    protected $description = 'Install Horizon as a system service (systemd/launchd)';

    public function handle(HorizonManager $horizonManager): int
    {
        // Check if web app is installed
        try {
            if ($horizonManager->isInstalled()) {
                if ($this->wantsJson()) {
                    return $this->outputJsonSuccess([
                        'installed' => true,
                        'already_installed' => true,
                    ]);
                }

                $this->info('Horizon service is already installed.');
                $this->info('Use horizon:restart to restart it, or horizon:uninstall to remove it.');

                return self::SUCCESS;
            }

            if (! $this->wantsJson()) {
                $this->info('Installing Horizon as a system service...');
            }

            $result = $horizonManager->install();

            if (! $result) {
                if ($this->wantsJson()) {
                    return $this->outputJsonError('Failed to install Horizon service');
                }

                $this->error('Failed to install Horizon service');

                return self::FAILURE;
            }

            // Start the service
            $started = $horizonManager->start();

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'installed' => true,
                    'started' => $started,
                ]);
            }

            if ($started) {
                $this->info('Horizon service installed and started successfully!');
            } else {
                $this->warn('Horizon service installed but failed to start. Check logs with: orbit horizon:logs');
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
