<?php

namespace App\Commands\Service;

use App\Concerns\WithJsonOutput;
use App\Services\ServiceManager;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class ServiceEnableCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'service:enable 
                            {service : Service name to enable}
                            {--json : Output as JSON}';

    protected $description = 'Enable a service';

    public function handle(ServiceManager $serviceManager): int
    {
        $serviceName = $this->argument('service');

        try {
            $success = $serviceManager->enable($serviceName);

            if (! $success) {
                return $this->wantsJson()
                    ? $this->outputJsonError("Failed to enable service: {$serviceName}")
                    : $this->handleError("Failed to enable service: {$serviceName}");
            }

            // Regenerate docker-compose.yaml to reflect changes
            $serviceManager->regenerateCompose();

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'service' => $serviceName,
                    'enabled' => true,
                    'message' => "Service {$serviceName} has been enabled",
                ]);
            }

            $this->newLine();
            $this->info("  Service '{$serviceName}' has been enabled");
            $this->line("  <fg=gray>Run 'orbit start {$serviceName}' to start the service</>");
            $this->newLine();

            return self::SUCCESS;

        } catch (RuntimeException $e) {
            return $this->wantsJson()
                ? $this->outputJsonError($e->getMessage())
                : $this->handleError($e->getMessage());
        }
    }

    protected function handleError(string $message): int
    {
        $this->newLine();
        $this->error("  {$message}");
        $this->newLine();

        return self::FAILURE;
    }
}
