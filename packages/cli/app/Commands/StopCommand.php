<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\CaddyManager;
use App\Services\HorizonManager;
use App\Services\PhpManager;
use App\Services\ServiceManager;
use LaravelZero\Framework\Commands\Command;

class StopCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'stop {--json : Output as JSON}';

    protected $description = 'Stop all Orbit services';

    public function handle(
        ServiceManager $serviceManager,
        PhpManager $phpManager,
        CaddyManager $caddyManager,
        HorizonManager $horizonManager
    ): int {
        $results = [];
        $usingFpm = $this->isUsingFpm($phpManager);

        // Stop Horizon first
        if ($usingFpm) {
            $result = $this->runStep('horizon', 'Stopping horizon', fn () => $horizonManager->stop());
            $results['horizon'] = $result;
        }

        if ($usingFpm) {
            // Stop host Caddy
            $result = $this->runStep('caddy', 'Stopping caddy', fn () => $caddyManager->stop());
            $results['caddy'] = $result;

            // Note: We don"t stop PHP-FPM pools to keep them available for other projects
        }

        // Stop all Docker services via ServiceManager
        $serviceResult = $this->runStep('services', 'Stopping Docker services', fn () => $serviceManager->stopAll());
        $results['docker_services'] = $serviceResult;

        $allSuccess = ! in_array(false, $results, true);

        if ($this->wantsJson()) {
            return $this->outputJson([
                'success' => $allSuccess,
                'data' => [
                    'action' => 'stop',
                    'architecture' => $usingFpm ? 'php-fpm' : 'frankenphp',
                    'services' => $results,
                ],
            ], $allSuccess ? self::SUCCESS : ExitCode::ServiceFailed->value);
        }

        $this->newLine();
        $this->info('Orbit stopped.');

        return $allSuccess ? self::SUCCESS : ExitCode::ServiceFailed->value;
    }

    private function isUsingFpm(PhpManager $phpManager): bool
    {
        $versions = ['8.3', '8.4'];
        foreach ($versions as $version) {
            $socketPath = $phpManager->getSocketPath($version);
            if (file_exists($socketPath)) {
                return true;
            }
        }

        return false;
    }

    private function runStep(string $name, string $label, callable $callback): bool
    {
        if ($this->wantsJson()) {
            try {
                return (bool) $callback();
            } catch (\Exception) {
                return false;
            }
        }

        $result = true;
        $this->task($label, function () use ($callback, &$result) {
            try {
                $result = (bool) $callback();
            } catch (\Exception) {
                $result = false;
            }

            return $result;
        });

        return $result;
    }
}
