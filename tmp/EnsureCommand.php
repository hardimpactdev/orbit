<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class EnsureCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'ensure {--json : Output as JSON}';

    protected $description = 'Ensure all Launchpad services are running (containers + Horizon)';

    protected array $requiredContainers = [
        'launchpad-dns',
        'launchpad-caddy',
        'launchpad-redis',
        'launchpad-reverb',
    ];

    public function handle(
        DockerManager $dockerManager,
        ConfigManager $configManager
    ): int {
        $results = [
            'docker' => false,
            'containers' => false,
            'horizon' => false,
        ];

        // 1. Check if Docker is running
        if (! $this->isDockerRunning()) {
            $this->logOrOutput('Docker is not running, skipping...', 'warn');

            return $this->outputResult($results);
        }
        $results['docker'] = true;

        // 2. Ensure containers are running
        $allRunning = true;
        foreach ($this->requiredContainers as $container) {
            if (! $dockerManager->isRunning($container)) {
                $allRunning = false;
                break;
            }
        }

        if (! $allRunning) {
            $this->logOrOutput('Starting containers...', 'info');
            $this->call('start');
        }
        $results['containers'] = true;

        // 3. Ensure Horizon is running
        if (! $this->isHorizonRunning($configManager)) {
            $this->logOrOutput('Starting Horizon...', 'info');
            $this->startHorizon($configManager);

            // Wait and verify
            sleep(3);

            if ($this->isHorizonRunning($configManager)) {
                $this->logOrOutput('Horizon started successfully', 'info');
                $results['horizon'] = true;
            } else {
                $this->logOrOutput('Horizon failed to start (Redis may not be ready)', 'warn');
            }
        } else {
            $results['horizon'] = true;
        }

        return $this->outputResult($results);
    }

    protected function isDockerRunning(): bool
    {
        $result = Process::run('docker info');

        return $result->successful();
    }

    protected function isHorizonRunning(ConfigManager $configManager): bool
    {
        $webAppPath = $configManager->getWebAppPath();

        if (! is_dir($webAppPath)) {
            return false;
        }

        $result = Process::path($webAppPath)->run('php artisan horizon:status');

        return str_contains($result->output(), 'Horizon is running');
    }

    protected function startHorizon(ConfigManager $configManager): void
    {
        $webAppPath = $configManager->getWebAppPath();
        $logPath = $configManager->getConfigPath().'/logs/horizon.log';

        // Ensure log directory exists
        $logDir = dirname($logPath);
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Start Horizon in background
        Process::path($webAppPath)
            ->start("php artisan horizon >> {$logPath} 2>&1");
    }

    protected function logOrOutput(string $message, string $type): void
    {
        if ($this->wantsJson()) {
            return;
        }

        match ($type) {
            'info' => $this->info($message),
            'warn' => $this->warn($message),
            'error' => $this->error($message),
            default => $this->line($message),
        };
    }

    protected function outputResult(array $results): int
    {
        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'docker' => $results['docker'],
                'containers' => $results['containers'],
                'horizon' => $results['horizon'],
                'all_running' => $results['docker'] && $results['containers'] && $results['horizon'],
            ]);
        }

        if ($results['docker'] && $results['containers'] && $results['horizon']) {
            $this->info('All services are running.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
