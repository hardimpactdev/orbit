<?php

namespace App\Commands\Service;

use App\Concerns\WithJsonOutput;
use App\Services\ServiceManager;
use LaravelZero\Framework\Commands\Command;

class ServiceDisableCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'service:disable 
                            {service : Service name to disable}
                            {--json : Output as JSON}';

    protected $description = 'Disable a service';

    public function handle(ServiceManager $serviceManager): int
    {
        $serviceName = $this->argument('service');

        try {
            $success = $serviceManager->disable($serviceName);

            if (! $success) {
                return $this->wantsJson()
                    ? $this->outputJsonError("Failed to disable service: {$serviceName}")
                    : $this->handleError("Failed to disable service: {$serviceName}");
            }

            // Regenerate docker-compose.yaml to reflect changes
            $serviceManager->regenerateCompose();

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'service' => $serviceName,
                    'enabled' => false,
                    'message' => "Service {$serviceName} has been disabled",
                ]);
            }

            $this->newLine();
            $this->info("  Service '{$serviceName}' has been disabled");
            $this->line("  <fg=gray>The service will not start with 'orbit start'</>");
            $this->newLine();

            return self::SUCCESS;

        } catch (\Exception $e) {
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
