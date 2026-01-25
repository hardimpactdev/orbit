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

    protected $description = 'Ensure all Orbit services are running (containers + Horizon)';

    /**
     * Required containers mapped to their service keys in DockerManager.
     */
    protected array $requiredServices = [
        'dns',
        'caddy',
        'redis',
        'reverb',
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

        // 2. Ensure containers are running - single batched query
        $allStatuses = $dockerManager->getAllStatuses();
        $allRunning = true;

        foreach ($this->requiredServices as $service) {
            if (! isset($allStatuses[$service]) || ! $allStatuses[$service]['running']) {
                $allRunning = false;
                break;
            }
        }

        if (! $allRunning) {
            $this->logOrOutput('Starting containers...', 'info');
            $this->call('start');
            $dockerManager->clearStatusCache(); // Clear cache after starting
        }
        $results['containers'] = true;

        // 3. Verify Horizon container is running
        // Check fresh status after potential start
        $freshStatuses = $dockerManager->getAllStatuses();

        // Horizon is not in the standard CONTAINERS list, check directly
        if ($dockerManager->isRunning('orbit-horizon')) {
            $results['horizon'] = true;
        } else {
            $this->logOrOutput('Horizon container not running, attempting to start...', 'warn');
            if ($dockerManager->start('horizon')) {
                sleep(3);
                $results['horizon'] = $dockerManager->isRunning('orbit-horizon');
            }
        }

        return $this->outputResult($results);
    }

    protected function isDockerRunning(): bool
    {
        $result = Process::run('docker info');

        return $result->successful();
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
