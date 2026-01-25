<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ServiceTemplate;

class ComposeGenerator
{
    public function __construct(protected ?ConfigManager $configManager = null, protected ?ServiceTemplateLoader $templateLoader = null)
    {
        $this->configManager ??= new ConfigManager;
        $this->templateLoader ??= new ServiceTemplateLoader;
    }

    /**
     * Generate docker-compose.yml content from service configurations.
     *
     * @param  array<string, array<string, mixed>>  $services  Service configurations from services.yaml
     * @return string YAML content for docker-compose.yml
     */
    public function generate(array $services): string
    {
        $compose = [
            'version' => '3.8',
            'services' => [],
            'networks' => [
                'orbit' => [
                    'external' => true,
                    'name' => 'orbit',
                ],
            ],
        ];

        // Sort services by dependencies
        $sortedServices = $this->sortByDependencies($services);

        foreach ($sortedServices as $serviceName => $config) {
            // Skip disabled services
            if (! ($config['enabled'] ?? false)) {
                continue;
            }

            // Load the template
            if (! $this->templateLoader->exists($serviceName)) {
                continue;
            }

            $template = $this->templateLoader->load($serviceName);
            $serviceConfig = $this->generateServiceConfig($template, $config);

            $compose['services'][$serviceName] = $serviceConfig;
        }

        return $this->arrayToYaml($compose);
    }

    /**
     * Generate configuration for a single service.
     *
     * @param  array<string, mixed>  $config  User configuration
     * @return array<string, mixed>
     */
    protected function generateServiceConfig(ServiceTemplate $template, array $config): array
    {
        $dockerConfig = $template->dockerConfig;
        $version = $config['version'] ?? $template->getDefaultVersion();

        // Start with base docker config
        $serviceConfig = [
            'container_name' => "orbit-{$template->name}",
        ];

        // Add image
        if (isset($dockerConfig['image'])) {
            $serviceConfig['image'] = $this->interpolate($dockerConfig['image'], [
                'version' => $version,
            ]);
        }

        // Add build config if present
        if (isset($dockerConfig['build'])) {
            $configPath = $this->configManager->getConfigPath();
            $serviceConfig['build'] = $this->interpolateArray($dockerConfig['build'], [
                'version' => $version,
                'config_path' => $configPath,
            ]);
        }

        // Add ports
        if (isset($dockerConfig['ports'])) {
            $serviceConfig['ports'] = $this->interpolateArray($dockerConfig['ports'], [
                'port' => $config['port'] ?? null,
            ]);
        }

        // Add volumes
        if (isset($dockerConfig['volumes'])) {
            $configPath = $this->configManager->getConfigPath();
            $dataPath = $config['data_path'] ?? "{$configPath}/data/{$template->name}";

            $serviceConfig['volumes'] = $this->interpolateArray($dockerConfig['volumes'], [
                'data_path' => $dataPath,
                'config_path' => $configPath,
            ]);
        }

        // Add environment variables
        if (isset($dockerConfig['environment'])) {
            $serviceConfig['environment'] = $this->interpolateArray($dockerConfig['environment'], [
                'version' => $version,
                'port' => $config['port'] ?? null,
            ]);
        }

        // Add custom environment variables from user config
        if (isset($config['environment']) && is_array($config['environment'])) {
            $serviceConfig['environment'] = array_merge(
                $serviceConfig['environment'] ?? [],
                $config['environment']
            );
        }

        // Add depends_on if template has dependencies
        if (! empty($template->dependsOn)) {
            $serviceConfig['depends_on'] = $template->dependsOn;
        }

        // Add restart policy
        $serviceConfig['restart'] = 'unless-stopped';

        // Add to orbit network
        $serviceConfig['networks'] = ['orbit'];

        // Add healthcheck if defined in docker config
        if (isset($dockerConfig['healthcheck'])) {
            $serviceConfig['healthcheck'] = $dockerConfig['healthcheck'];
        }

        return $serviceConfig;
    }

    /**
     * Sort services by dependencies (topological sort).
     *
     * @param  array<string, array<string, mixed>>  $services
     * @return array<string, array<string, mixed>>
     */
    protected function sortByDependencies(array $services): array
    {
        $sorted = [];
        $visited = [];

        $visit = function (string $serviceName) use (&$visit, &$sorted, &$visited, $services): void {
            if (isset($visited[$serviceName])) {
                return;
            }

            $visited[$serviceName] = true;

            // Skip if service doesn't exist
            if (! isset($services[$serviceName])) {
                return;
            }

            // Visit dependencies first
            if ($this->templateLoader->exists($serviceName)) {
                $template = $this->templateLoader->load($serviceName);
                foreach ($template->dependsOn as $dependency) {
                    $visit($dependency);
                }
            }

            $sorted[$serviceName] = $services[$serviceName];
        };

        foreach (array_keys($services) as $serviceName) {
            $visit($serviceName);
        }

        return $sorted;
    }

    /**
     * Interpolate variables in a string.
     *
     * @param  array<string, mixed>  $variables
     */
    protected function interpolate(string $value, array $variables): string
    {
        foreach ($variables as $key => $val) {
            if ($val === null) {
                continue;
            }
            $pattern = '${'.$key.'}';
            $value = str_replace($pattern, (string) $val, $value);
        }

        return $value;
    }

    /**
     * Interpolate variables in an array recursively.
     *
     * @param  array<mixed>  $array
     * @param  array<string, mixed>  $variables
     * @return array<mixed>
     */
    protected function interpolateArray(array $array, array $variables): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $this->interpolate($value, $variables);
            } elseif (is_array($value)) {
                $result[$key] = $this->interpolateArray($value, $variables);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Convert array to YAML string (simple implementation).
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function arrayToYaml(array $data, int $indent = 0): string
    {
        $yaml = '';
        $prefix = str_repeat('  ', $indent);

        if ($indent === 0) {
            $yaml = "# Generated by Orbit\n# Do not edit manually\n\n";
        }

        foreach ($data as $key => $value) {
            if (is_int($key)) {
                // List item
                if (is_array($value)) {
                    $yaml .= $prefix.'- '.ltrim($this->arrayToYaml($value, $indent + 1));
                } else {
                    $yaml .= $prefix.'- '.$this->formatValue($value)."\n";
                }
            } else {
                // Key-value pair
                if (is_array($value)) {
                    if (empty($value)) {
                        $yaml .= $prefix.$key.": []\n";
                    } elseif ($this->isSequentialArray($value)) {
                        $yaml .= $prefix.$key.":\n";
                        foreach ($value as $item) {
                            if (is_array($item)) {
                                $yaml .= $prefix.'  - '.ltrim($this->arrayToYaml($item, $indent + 2));
                            } else {
                                $yaml .= $prefix.'  - '.$this->formatValue($item)."\n";
                            }
                        }
                    } else {
                        $yaml .= $prefix.$key.":\n";
                        $yaml .= $this->arrayToYaml($value, $indent + 1);
                    }
                } else {
                    $yaml .= $prefix.$key.': '.$this->formatValue($value)."\n";
                }
            }
        }

        return $yaml;
    }

    /**
     * Check if array is sequential (numeric keys starting from 0).
     */
    protected function isSequentialArray(array $array): bool
    {
        return array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * Format a value for YAML output.
     */
    protected function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            // Quote strings that might be interpreted as other types
            if (in_array(strtolower($value), ['true', 'false', 'null', 'yes', 'no', 'on', 'off', ''])) {
                return '"'.$value.'"';
            }

            // Quote numeric strings (e.g., "3.8" for version)
            if (is_numeric($value)) {
                return '"'.$value.'"';
            }

            // Quote strings with special characters
            if (preg_match('/[:#\[\]{}|>&*!?]/', $value) || str_contains($value, "\n")) {
                return '"'.addslashes($value).'"';
            }

            return $value;
        }

        return (string) $value;
    }

    /**
     * Write generated compose file to disk.
     */
    public function write(string $content): void
    {
        $path = $this->configManager->getConfigPath().'/docker-compose.yaml';
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $content);
    }

    /**
     * Generate and write compose file.
     *
     * @param  array<string, array<string, mixed>>  $services
     */
    public function generateAndWrite(array $services): string
    {
        $content = $this->generate($services);
        $this->write($content);

        return $content;
    }
}
