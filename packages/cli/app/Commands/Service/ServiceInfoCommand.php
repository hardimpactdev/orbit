<?php

namespace App\Commands\Service;

use App\Concerns\WithJsonOutput;
use App\Services\ServiceManager;
use App\Services\ServiceTemplateLoader;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class ServiceInfoCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'service:info 
                            {service : Service name}
                            {--json : Output as JSON}';

    protected $description = 'Show detailed information about a service';

    public function handle(ServiceManager $serviceManager, ServiceTemplateLoader $templateLoader): int
    {
        $serviceName = $this->argument('service');

        // Get service configuration
        $config = $serviceManager->getService($serviceName);

        // Get template information if available
        $template = null;
        $templateExists = $templateLoader->exists($serviceName);

        if ($templateExists) {
            try {
                $template = $templateLoader->load($serviceName);
            } catch (RuntimeException) {
                // Template exists but couldn't be loaded
            }
        }

        if ($this->wantsJson()) {
            $data = [
                'service' => $serviceName,
                'configured' => $config !== null,
            ];

            if ($config !== null) {
                $data['configuration'] = $config;
            }

            if ($template !== null) {
                $data['template'] = [
                    'name' => $template->name,
                    'label' => $template->label,
                    'description' => $template->description,
                    'category' => $template->category,
                    'versions' => $template->versions,
                    'configSchema' => $template->configSchema,
                    'dependsOn' => $template->dependsOn,
                ];
            }

            return $this->outputJsonSuccess($data);
        }

        // Human-readable output
        $this->newLine();

        if ($config === null && $template === null) {
            $this->error("  Service '{$serviceName}' not found");
            $this->line("  <fg=gray>Use 'orbit service:list --available' to see available services</>");
            $this->newLine();

            return self::FAILURE;
        }

        // Show template information
        if ($template !== null) {
            $this->line("  <fg=cyan>{$template->label}</> ({$template->name})");
            $this->line("  {$template->description}");
            $this->newLine();

            $this->line('  <fg=cyan>Category:</> '.$template->category);
            $this->line('  <fg=cyan>Available versions:</> '.implode(', ', $template->versions));

            if (! empty($template->dependsOn)) {
                $this->line('  <fg=cyan>Dependencies:</> '.implode(', ', $template->dependsOn));
            }

            $this->newLine();
        }

        // Show current configuration
        if ($config !== null) {
            $enabled = $config['enabled'] ?? false;
            $statusLabel = $enabled ? '<fg=green>enabled</>' : '<fg=gray>disabled</>';

            $this->line("  <fg=cyan>Status:</> {$statusLabel}");
            $this->newLine();

            $this->line('  <fg=cyan>Configuration:</>');
            foreach ($config as $key => $value) {
                $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                $this->line("    {$key}: {$displayValue}");
            }
        } else {
            $this->line('  <fg=yellow>Not configured</>');
            $this->line("  <fg=gray>Run 'orbit service:enable {$serviceName}' to enable this service</>");
        }

        // Show configuration schema if available
        if ($template !== null && ! empty($template->configSchema['properties'])) {
            $this->newLine();
            $this->line('  <fg=cyan>Available configuration options:</>');

            foreach ($template->configSchema['properties'] as $key => $schema) {
                $type = $schema['type'] ?? 'string';
                $default = isset($schema['default']) ? " (default: {$schema['default']})" : '';
                $required = in_array($key, $template->configSchema['required'] ?? [], true) ? ' <fg=red>*</>' : '';

                $this->line("    {$key}: <fg=gray>{$type}</>{$default}{$required}");
            }

            $this->newLine();
            $this->line('  <fg=gray>Use \'orbit service:configure '.$serviceName.' --set key=value\' to configure</>');
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
