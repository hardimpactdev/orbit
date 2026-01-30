<?php
declare(strict_types=1);

namespace App\Commands\Service;

use App\Concerns\WithJsonOutput;
use App\Services\ServiceManager;
use App\Services\ServiceTemplateLoader;
use LaravelZero\Framework\Commands\Command;

class ServiceListCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'service:list 
                            {--available : Show available service templates instead of configured services}
                            {--json : Output as JSON}';

    protected $description = 'List configured services or available service templates';

    public function handle(ServiceManager $serviceManager, ServiceTemplateLoader $templateLoader): int
    {
        if ($this->option('available')) {
            return $this->listAvailable($templateLoader);
        }

        return $this->listConfigured($serviceManager);
    }

    protected function listConfigured(ServiceManager $serviceManager): int
    {
        $services = $serviceManager->getServices();
        $enabled = $serviceManager->getEnabled();

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'services' => $services,
                'enabled' => array_keys($enabled),
                'total' => count($services),
                'enabled_count' => count($enabled),
            ]);
        }

        $this->newLine();
        $this->line('  <fg=cyan>Configured Services:</> ('.count($services).' total, '.count($enabled).' enabled)');
        $this->newLine();

        if (empty($services)) {
            $this->line('  <fg=yellow>No services configured. Use --available to see available templates.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        foreach ($services as $name => $config) {
            $enabled = $config['enabled'] ?? false;
            $version = $config['version'] ?? 'default';

            $statusIcon = $enabled ? '<fg=green>●</>' : '<fg=gray>○</>';
            $statusLabel = $enabled ? '<fg=green>enabled</>' : '<fg=gray>disabled</>';

            $this->line("    {$statusIcon} <fg=white>{$name}</> ({$statusLabel}) - version: {$version}");
        }

        $this->newLine();

        return self::SUCCESS;
    }

    protected function listAvailable(ServiceTemplateLoader $templateLoader): int
    {
        $templates = $templateLoader->loadAll();
        $names = array_keys($templates);

        if ($this->wantsJson()) {
            $data = [];
            foreach ($templates as $name => $template) {
                $data[$name] = [
                    'name' => $template->name,
                    'label' => $template->label,
                    'description' => $template->description,
                    'category' => $template->category,
                    'versions' => $template->versions,
                    'dependsOn' => $template->dependsOn,
                ];
            }

            return $this->outputJson([
                'success' => true,
                'available' => $names,
                'data' => [
                    'templates' => $data,
                    'total' => count($templates),
                ],
            ]);
        }

        $this->newLine();
        $this->line('  <fg=cyan>Available Service Templates:</> ('.count($templates).' available)');
        $this->newLine();

        if (empty($templates)) {
            $this->line('  <fg=yellow>No service templates found.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        // Group by category
        $byCategory = [];
        foreach ($templates as $name => $template) {
            $category = $template->category;
            if (! isset($byCategory[$category])) {
                $byCategory[$category] = [];
            }
            $byCategory[$category][$name] = $template;
        }

        foreach ($byCategory as $category => $categoryTemplates) {
            $this->line('  <fg=yellow>'.ucfirst($category).':</>');

            foreach ($categoryTemplates as $name => $template) {
                $versions = implode(', ', $template->versions);
                $this->line("    <fg=white>{$template->label}</> ({$name})");
                $this->line("      {$template->description}");
                $this->line("      <fg=gray>Versions:</> {$versions}");

                if (! empty($template->dependsOn)) {
                    $deps = implode(', ', $template->dependsOn);
                    $this->line("      <fg=gray>Depends on:</> {$deps}");
                }
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }
}
