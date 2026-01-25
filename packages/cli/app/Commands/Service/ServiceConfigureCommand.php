<?php

namespace App\Commands\Service;

use App\Concerns\WithJsonOutput;
use App\Services\ServiceManager;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class ServiceConfigureCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'service:configure 
                            {service : Service name to configure}
                            {--set=* : Configuration in key=value format}
                            {--json : Output as JSON}';

    protected $description = 'Configure a service with custom settings';

    public function handle(ServiceManager $serviceManager): int
    {
        $serviceName = $this->argument('service');
        $setOptions = $this->option('set');

        if (empty($setOptions)) {
            return $this->wantsJson()
                ? $this->outputJsonError('No configuration provided. Use --set key=value')
                : $this->handleError('No configuration provided. Use --set key=value');
        }

        try {
            // Parse key=value pairs
            $config = $this->parseSetOptions($setOptions);

            // Configure the service
            $success = $serviceManager->configure($serviceName, $config);

            if (! $success) {
                return $this->wantsJson()
                    ? $this->outputJsonError("Failed to configure service: {$serviceName}")
                    : $this->handleError("Failed to configure service: {$serviceName}");
            }

            // Regenerate docker-compose.yaml to reflect changes
            $serviceManager->regenerateCompose();

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'service' => $serviceName,
                    'configuration' => $config,
                    'message' => "Service {$serviceName} has been configured",
                ]);
            }

            $this->newLine();
            $this->info("  Service '{$serviceName}' has been configured");
            $this->newLine();
            $this->line('  <fg=cyan>Updated configuration:</>');
            foreach ($config as $key => $value) {
                $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                $this->line("    {$key}: {$displayValue}");
            }
            $this->newLine();
            $this->line("  <fg=gray>Run 'orbit restart {$serviceName}' to apply changes</>");
            $this->newLine();

            return self::SUCCESS;

        } catch (RuntimeException $e) {
            return $this->wantsJson()
                ? $this->outputJsonError($e->getMessage())
                : $this->handleError($e->getMessage());
        }
    }

    /**
     * Parse --set options into configuration array.
     *
     * @param  array<string>  $setOptions
     * @return array<string, mixed>
     */
    protected function parseSetOptions(array $setOptions): array
    {
        $config = [];

        foreach ($setOptions as $option) {
            if (! str_contains($option, '=')) {
                throw new RuntimeException("Invalid format: {$option}. Expected key=value");
            }

            [$key, $value] = explode('=', $option, 2);
            $key = trim($key);
            $value = trim($value);

            // Parse value types
            if ($value === 'true') {
                $value = true;
            } elseif ($value === 'false') {
                $value = false;
            } elseif (is_numeric($value)) {
                $value = str_contains($value, '.') ? (float) $value : (int) $value;
            }

            $config[$key] = $value;
        }

        return $config;
    }

    protected function handleError(string $message): int
    {
        $this->newLine();
        $this->error("  {$message}");
        $this->newLine();

        return self::FAILURE;
    }
}
