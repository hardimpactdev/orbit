<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\RendersShowDetails;

abstract class InstanceCommand extends AppGatewayCommand
{
    use RendersShowDetails;

    /**
     * @return array{app: string, instance: string}|int
     */
    protected function resolveInstanceSelector(): array|int
    {
        $selector = $this->stringArgument('instance');

        if ($selector === null) {
            return $this->failValidation('instance', 'The instance argument is required.');
        }

        $separator = strrpos($selector, '.');

        if ($separator === false || $separator === 0 || $separator === (strlen($selector) - 1)) {
            return $this->failValidation(
                'instance',
                'Use a app.instance selector, for example billing.production.',
            );
        }

        return [
            'app' => substr($selector, 0, $separator),
            'instance' => substr($selector, $separator + 1),
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    protected function instancesFromGatewayResponse(array $response): array
    {
        $instances = $this->successData($response)['instances'] ?? null;

        if (! is_array($instances)) {
            return [];
        }

        $normalized = [];

        foreach ($instances as $instance) {
            $instance = $this->associativeArray($instance);

            if ($instance === null) {
                continue;
            }

            $normalized[] = $instance;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    protected function instanceFromGatewayResponse(array $response): ?array
    {
        $instance = $this->successData($response)['instance'] ?? null;

        return $this->associativeArray($instance);
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    protected function renderInstance(array $instance): void
    {
        $name = $this->instanceString($instance, 'name');
        $runtime = is_array($instance['runtime'] ?? null) ? $instance['runtime'] : [];

        $this->renderShowDetails("Instance: {$name}", [
            'App' => $instance['app'] ?? null,
            'Driver' => $instance['driver'] ?? null,
            'Mode' => $runtime['mode'] ?? null,
            'PHP' => $runtime['php_version'] ?? null,
            'Extensions' => $this->extensionsList($runtime['required_php_extensions'] ?? null),
            'Deployment' => $instance['latest_deployment_status'] ?? null,
            'Config' => $this->driverConfigPairs($instance['driver_config'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    protected function instanceString(array $instance, string $key): string
    {
        $value = $instance[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : '—';
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    protected function runtimeString(array $instance, string $key): string
    {
        $runtime = is_array($instance['runtime'] ?? null) ? $instance['runtime'] : [];
        $value = $runtime[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : '—';
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    protected function extensionsLabel(array $instance): string
    {
        $extensions = $this->extensionsList(
            is_array($instance['runtime'] ?? null) ? $instance['runtime']['required_php_extensions'] ?? null : null,
        );

        return $extensions === [] ? '—' : implode(', ', $extensions);
    }

    /**
     * @return list<string>
     */
    protected function extensionsList(mixed $extensions): array
    {
        if (! is_array($extensions)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $extension): ?string => is_string($extension) && $extension !== ''
                    ? $extension
                    : null,
                $extensions,
            ),
            static fn (?string $extension): bool => $extension !== null,
        ));
    }

    /**
     * @return list<string>
     */
    protected function driverConfigPairs(mixed $config): array
    {
        if (! is_array($config)) {
            return [];
        }

        $pairs = [];

        foreach ($config as $key => $value) {
            if (! is_string($key) || ! is_scalar($value) || (string) $value === '') {
                continue;
            }

            $pairs[] = "{$key}={$value}";
        }

        return $pairs;
    }

    /**
     * @return array<string, mixed>
     */
    protected function addPayload(string $instance): array
    {
        return array_filter(
            [
                'name' => $instance,
                'driver' => $this->stringOption('driver') ?? 'orbit',
                'node' => $this->stringOption('node'),
                'path' => $this->stringOption('path'),
                'root' => $this->stringOption('root'),
                'domain' => $this->stringOption('domain'),
                'cloud_application' => $this->stringOption('cloud-app'),
                'cloud_environment' => $this->stringOption('cloud-environment'),
                'cloud_application_id' => $this->stringOption('cloud-application-id'),
                'cloud_application_name' => $this->stringOption('cloud-application-name'),
                'cloud_environment_id' => $this->stringOption('cloud-environment-id'),
                'cloud_environment_name' => $this->stringOption('cloud-environment-name'),
                'cloud_organization_id' => $this->stringOption('cloud-organization-id'),
                'cloud_organization_name' => $this->stringOption('cloud-organization-name'),
                'php_extensions' => $this->phpExtensions(),
            ],
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }

    /**
     * @return list<string>
     */
    private function phpExtensions(): array
    {
        $extensions = $this->option('php-extension');

        return is_array($extensions) ? array_values(array_filter($extensions, is_string(...))) : [];
    }
}
