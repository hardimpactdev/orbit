<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\CaddyfileGenerator;
use App\Services\CaddyManager;
use App\Services\HorizonManager;
use App\Services\PhpManager;
use App\Services\ServiceManager;
use LaravelZero\Framework\Commands\Command;

class StartCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'start {--json : Output as JSON}';

    protected $description = 'Start all Orbit services';

    public function handle(
        ServiceManager $serviceManager,
        CaddyfileGenerator $caddyfileGenerator,
        PhpManager $phpManager,
        CaddyManager $caddyManager,
        HorizonManager $horizonManager
    ): int {
        $results = [];
        $usingFpm = $this->isUsingFpm($phpManager);

        // Generate configuration
        $configResult = $this->runStep('config', 'Generating configuration', function () use ($caddyfileGenerator) {
            $caddyfileGenerator->generate();

            return true;
        });
        $results['config'] = $configResult;
        $allSuccess = $configResult;

        if ($usingFpm) {
            // PHP-FPM Architecture
            // Start PHP-FPM pools
            $result = $this->runStep('php', 'Starting php', function () use ($phpManager) {
                $versions = ['8.3', '8.4'];
                foreach ($versions as $version) {
                    if ($phpManager->isInstalled($version)) {
                        $phpManager->start($version);
                    }
                }

                return true;
            });
            $results['php'] = $result;
            $allSuccess = $allSuccess && $result;

            // Start host Caddy
            $result = $this->runStep('caddy', 'Starting caddy', fn () => $caddyManager->start());
            $results['caddy'] = $result;
            $allSuccess = $allSuccess && $result;
        }

        // Start all Docker services via ServiceManager
        $serviceResult = $this->runStep('services', 'Starting Docker services', fn () => $serviceManager->startAll());
        $results['docker_services'] = $serviceResult;
        $allSuccess = $allSuccess && $serviceResult;

        // Start Horizon
        if ($usingFpm) {
            $result = $this->runStep('horizon', 'Starting horizon', fn () => $horizonManager->start());
            $results['horizon'] = $result;
            $allSuccess = $allSuccess && $result;
        }

        if ($this->wantsJson()) {
            return $this->outputJson([
                'success' => $allSuccess,
                'data' => [
                    'action' => 'start',
                    'architecture' => $usingFpm ? 'php-fpm' : 'frankenphp',
                    'services' => $results,
                ],
            ], $allSuccess ? self::SUCCESS : ExitCode::ServiceFailed->value);
        }

        $this->newLine();
        if ($allSuccess) {
            $this->info('Orbit is running!');
        } else {
            $this->warn('Some services failed to start.');
        }

        return $allSuccess ? self::SUCCESS : ExitCode::ServiceFailed->value;
    }

    private function isUsingFpm(PhpManager $phpManager): bool
    {
        // Check if any FPM socket exists
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
