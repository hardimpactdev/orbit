<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class ServiceManager
{
    protected ComposeGenerator $composeGenerator;

    protected string $servicesPath;

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $services = [];

    public function __construct(
        protected ?ConfigManager $configManager = null,
        ?ComposeGenerator $composeGenerator = null,
        protected ?ServiceTemplateLoader $templateLoader = null,
        protected ?ServiceConfigValidator $validator = null
    ) {
        $this->configManager ??= new ConfigManager;
        $this->templateLoader ??= new ServiceTemplateLoader;
        $this->validator ??= new ServiceConfigValidator;
        $this->composeGenerator = $composeGenerator ?? new ComposeGenerator($this->configManager);
        $this->servicesPath = $this->configManager->getConfigPath().'/services.yaml';

        $this->loadServices();
    }

    /**
     * Load services from services.yaml file.
     */
    public function loadServices(): void
    {
        // If services.yaml doesn't exist, create from stub
        if (! file_exists($this->servicesPath)) {
            $this->initializeFromStub();
        }

        $content = file_get_contents($this->servicesPath);
        if ($content === false) {
            throw new RuntimeException('Failed to read services.yaml');
        }

        $data = $this->parseYaml($content);
        $this->services = $data['services'] ?? [];
    }

    /**
     * Simple YAML parser for services configuration.
     * Only handles the specific structure we need.
     */
    protected function parseYaml(string $yaml): array
    {
        $lines = explode("\n", $yaml);
        $result = [];
        $currentService = null;
        $currentSection = null;

        foreach ($lines as $line) {
            // Skip comments and empty lines
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            // Root level (services:)
            if (! str_starts_with($line, ' ')) {
                $currentSection = trim($line, ': ');
                $result[$currentSection] = [];

                continue;
            }

            // Service level (  redis:)
            if (str_starts_with($line, '  ') && ! str_starts_with($line, '    ')) {
                $currentService = trim($line, ': ');
                $result[$currentSection][$currentService] = [];

                continue;
            }

            // Property level (    enabled: true)
            if (str_starts_with($line, '    ') && $currentService !== null) {
                [$key, $value] = explode(':', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Parse value types
                if ($value === 'true') {
                    $value = true;
                } elseif ($value === 'false') {
                    $value = false;
                } elseif (is_numeric($value)) {
                    $value = (int) $value;
                } else {
                    // Remove quotes
                    $value = trim($value, '"');
                }

                // Handle environment section
                if ($key === 'environment') {
                    $result[$currentSection][$currentService][$key] = [];

                    continue;
                }

                $result[$currentSection][$currentService][$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Convert array to YAML format.
     */
    protected function toYaml(array $data): string
    {
        $yaml = '';

        foreach ($data as $section => $services) {
            $yaml .= $section.":\n";

            foreach ($services as $serviceName => $config) {
                $yaml .= "  $serviceName:\n";

                foreach ($config as $key => $value) {
                    if (is_array($value)) {
                        // Handle nested arrays (like environment variables)
                        if (empty($value)) {
                            $yaml .= "    $key: []\n";
                        } else {
                            $yaml .= "    $key:\n";
                            foreach ($value as $subKey => $subValue) {
                                if (is_numeric($subKey)) {
                                    // Indexed array - use list format
                                    $yaml .= "      - $subValue\n";
                                } else {
                                    // Associative array - use key: value format
                                    $yaml .= "      $subKey: $subValue\n";
                                }
                            }
                        }
                    } elseif (is_bool($value)) {
                        $value = $value ? 'true' : 'false';
                        $yaml .= "    $key: $value\n";
                    } elseif (is_string($value)) {
                        $value = '"'.$value.'"';
                        $yaml .= "    $key: $value\n";
                    } else {
                        $yaml .= "    $key: $value\n";
                    }
                }
            }
        }

        return $yaml;
    }

    /**
     * Initialize services.yaml from stub.
     */
    protected function initializeFromStub(): void
    {
        $stubPath = $this->getStubPath();

        if (! file_exists($stubPath)) {
            throw new RuntimeException('services.yaml.stub not found');
        }

        $content = file_get_contents($stubPath);
        if ($content === false) {
            throw new RuntimeException('Failed to read services.yaml.stub');
        }

        // Create config directory if it doesn't exist
        $configPath = $this->configManager->getConfigPath();
        if (! is_dir($configPath)) {
            mkdir($configPath, 0755, true);
        }

        file_put_contents($this->servicesPath, $content);
    }

    /**
     * Get path to services.yaml.stub.
     */
    protected function getStubPath(): string
    {
        if (function_exists('base_path')) {
            return base_path('stubs/services.yaml.stub');
        }

        return getcwd().'/stubs/services.yaml.stub';
    }

    /**
     * Save services to services.yaml file.
     */
    public function saveServices(): bool
    {
        $data = ['services' => $this->services];
        $content = $this->toYaml($data);

        $result = file_put_contents($this->servicesPath, $content);

        return $result !== false;
    }

    /**
     * Get all services (both enabled and disabled).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * Get enabled services only.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getEnabled(): array
    {
        return array_filter($this->services, fn ($config) => $config['enabled'] ?? false);
    }

    /**
     * Get a specific service configuration.
     *
     * @return array<string, mixed>|null
     */
    public function getService(string $name): ?array
    {
        return $this->services[$name] ?? null;
    }

    /**
     * Check if a service is enabled.
     */
    public function isEnabled(string $name): bool
    {
        return $this->services[$name]['enabled'] ?? false;
    }

    /**
     * Enable a service.
     */
    public function enable(string $name): bool
    {
        if (! isset($this->services[$name])) {
            // Service doesn't exist, create with defaults from template
            if (! $this->templateLoader->exists($name)) {
                throw new RuntimeException("Service template not found: {$name}");
            }

            $template = $this->templateLoader->load($name);
            $this->services[$name] = [
                'enabled' => true,
                'version' => $template->getDefaultVersion(),
            ];
        } else {
            $this->services[$name]['enabled'] = true;
        }

        return $this->saveServices();
    }

    /**
     * Disable a service.
     */
    public function disable(string $name): bool
    {
        if (! isset($this->services[$name])) {
            return true; // Already doesn't exist
        }

        // Check if service is required
        if ($this->templateLoader->exists($name)) {
            $template = $this->templateLoader->load($name);
            if ($template->isRequired()) {
                throw new RuntimeException("Service '{$name}' is required and cannot be disabled");
            }
        }

        unset($this->services[$name]);

        return $this->saveServices();
    }

    /**
     * Configure a service with custom settings.
     *
     * @param  array<string, mixed>  $config
     */
    public function configure(string $name, array $config): bool
    {
        // Validate configuration against template schema
        if ($this->templateLoader->exists($name)) {
            $template = $this->templateLoader->load($name);
            $result = $this->validator->validate($config, $template);
            if (! $result['valid']) {
                throw new RuntimeException('Invalid configuration: '.implode(', ', $result['errors']));
            }
        }

        // Merge with existing config or create new
        if (isset($this->services[$name])) {
            $this->services[$name] = array_merge($this->services[$name], $config);
        } else {
            $this->services[$name] = $config;
        }

        return $this->saveServices();
    }

    /**
     * Regenerate docker-compose.yaml from current services configuration.
     */
    public function regenerateCompose(): bool
    {
        $content = $this->composeGenerator->generate($this->services);
        $this->composeGenerator->write($content);

        return true;
    }

    /**
     * Start a specific service.
     */
    public function start(string $name): bool
    {
        if (! $this->isEnabled($name)) {
            throw new RuntimeException("Service not enabled: {$name}");
        }

        // Regenerate dnsmasq.conf if starting DNS service
        if ($name === 'dns') {
            $this->configManager->writeDnsmasqConf();
        }

        // Regenerate compose to ensure it's up to date
        $this->regenerateCompose();

        // Use docker compose to start the service
        $composePath = $this->configManager->getConfigPath().'/docker-compose.yaml';
        $result = shell_exec("docker compose -f {$composePath} up -d {$name} 2>&1");

        return $result !== null && ! str_contains($result, 'error');
    }

    /**
     * Stop a specific service.
     */
    public function stop(string $name): bool
    {
        $composePath = $this->configManager->getConfigPath().'/docker-compose.yaml';
        $result = shell_exec("docker compose -f {$composePath} stop {$name} 2>&1");

        return $result !== null && ! str_contains($result, 'error');
    }

    /**
     * Start all enabled services.
     */
    public function startAll(): bool
    {
        // Regenerate dnsmasq.conf if DNS service is enabled
        if ($this->isEnabled('dns')) {
            $this->configManager->writeDnsmasqConf();
        }

        // Regenerate compose to ensure it's up to date
        $this->regenerateCompose();

        $composePath = $this->configManager->getConfigPath().'/docker-compose.yaml';
        $result = shell_exec("docker compose -f {$composePath} up -d 2>&1");

        return $result !== null && ! str_contains($result, 'error');
    }

    /**
     * Stop all services.
     */
    public function stopAll(): bool
    {
        $composePath = $this->configManager->getConfigPath().'/docker-compose.yaml';
        $result = shell_exec("docker compose -f {$composePath} down 2>&1");

        return $result !== null && ! str_contains($result, 'error');
    }

    /**
     * Get available service templates.
     *
     * @return array<string>
     */
    public function getAvailableServices(): array
    {
        return $this->templateLoader->getAvailable();
    }

    /**
     * Remove a service configuration.
     */
    public function remove(string $name): bool
    {
        unset($this->services[$name]);

        return $this->saveServices();
    }
}
