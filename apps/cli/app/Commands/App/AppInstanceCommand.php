<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\RendersShowDetails;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class AppInstanceCommand extends AppGatewayCommand
{
    use RendersShowDetails;

    #[\Override]
    protected $signature = 'app:instance
        {action? : Action to perform (list|show|add|remove)}
        {app? : App name or hostname}
        {--app= : App name or hostname}
        {--instance= : App instance name}
        {--driver=orbit : Instance driver (orbit|laravel-cloud)}
        {--node= : Orbit node for orbit driver}
        {--path= : Orbit app path for orbit driver}
        {--root= : Orbit document root for orbit driver}
        {--domain= : Primary domain}
        {--cloud-app= : Laravel Cloud application id or name}
        {--cloud-environment= : Laravel Cloud environment id or name}
        {--cloud-application-id= : Laravel Cloud application id}
        {--cloud-application-name= : Laravel Cloud application name}
        {--cloud-environment-id= : Laravel Cloud environment id}
        {--cloud-environment-name= : Laravel Cloud environment name}
        {--cloud-organization-id= : Laravel Cloud organization id}
        {--cloud-organization-name= : Laravel Cloud organization name}
        {--php-extension=* : Required PHP extension for this instance}
        {--force : Confirm destructive remove}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'List or change concrete runtime/deploy instances for an app.';

    public function handle(): int
    {
        $action = $this->stringArgument('action');

        if ($action === null) {
            return $this->failValidation('action', 'Action is required.');
        }

        if (! in_array($action, ['list', 'show', 'add', 'remove'], true)) {
            return $this->renderFailure(
                'validation_failed',
                'Action must be one of: list, show, add, remove.',
                ['field' => 'action', 'allowed' => ['list', 'show', 'add', 'remove']],
            );
        }

        $app = $this->appSelector();

        if (is_int($app)) {
            return $app;
        }

        return match ($action) {
            'list' => $this->listInstances($app),
            'show' => $this->showInstance($app),
            'add' => $this->addInstance($app),
            'remove' => $this->removeInstance($app),
        };
    }

    private function listInstances(string $app): int
    {
        try {
            $response = $this->gatewayGet($this->apiAppPath($app, '/instances'));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $instances = $this->instancesFromGatewayResponse($response);

        if ($instances === []) {
            $this->line('No instances found.');

            return self::SUCCESS;
        }

        table(
            headers: ['APP', 'NAME', 'DRIVER', 'MODE', 'PHP', 'EXTENSIONS', 'DEPLOYMENT'],
            rows: array_map(fn (array $instance): array => [
                $this->instanceString($instance, 'app'),
                $this->instanceString($instance, 'name'),
                $this->instanceString($instance, 'driver'),
                $this->runtimeString($instance, 'mode'),
                $this->runtimeString($instance, 'php_version'),
                $this->extensionsLabel($instance),
                $this->instanceString($instance, 'latest_deployment_status'),
            ], $instances),
        );

        return self::SUCCESS;
    }

    private function showInstance(string $app): int
    {
        $instance = $this->stringOption('instance');

        if ($instance === null) {
            return $this->failValidation('instance', 'The --instance option is required.');
        }

        try {
            $response = $this->gatewayGet($this->apiAppPath($app, '/instances/'.rawurlencode($instance)));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $payload = $this->instanceFromGatewayResponse($response);

        if ($payload === null) {
            return $this->renderFailure('gateway_unavailable', 'Gateway response missing required instance data.');
        }

        $this->renderInstance($payload);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function instancesFromGatewayResponse(array $response): array
    {
        $instances = $response['success']['data']['instances'] ?? null;

        if (! is_array($instances)) {
            return [];
        }

        return array_values(array_filter($instances, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    private function instanceFromGatewayResponse(array $response): ?array
    {
        $instance = $response['success']['data']['instance'] ?? null;

        return is_array($instance) ? $instance : null;
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    private function renderInstance(array $instance): void
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
    private function runtimeString(array $instance, string $key): string
    {
        $runtime = is_array($instance['runtime'] ?? null) ? $instance['runtime'] : [];
        $value = $runtime[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    private function extensionsLabel(array $instance): string
    {
        $extensions = $this->extensionsList(
            is_array($instance['runtime'] ?? null) ? ($instance['runtime']['required_php_extensions'] ?? null) : null,
        );

        return $extensions === [] ? '—' : implode(', ', $extensions);
    }

    /**
     * @return list<string>
     */
    private function extensionsList(mixed $extensions): array
    {
        if (! is_array($extensions)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $extension): ?string => is_string($extension) && $extension !== '' ? $extension : null, $extensions),
            static fn (?string $extension): bool => $extension !== null,
        ));
    }

    /**
     * @return list<string>
     */
    private function driverConfigPairs(mixed $config): array
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
     * @param  array<string, mixed>  $instance
     */
    private function instanceString(array $instance, string $key): string
    {
        $value = $instance[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }

    private function addInstance(string $app): int
    {
        $instance = $this->stringOption('instance');

        if ($instance === null) {
            return $this->failValidation('instance', 'The --instance option is required.');
        }

        try {
            $response = $this->gatewayPost($this->apiAppPath($app, '/instances'), $this->addPayload($instance));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderAddedInstance($app, $instance, $response);
    }

    private function removeInstance(string $app): int
    {
        $instance = $this->stringOption('instance');

        if ($instance === null) {
            return $this->failValidation('instance', 'The --instance option is required.');
        }

        if (! (bool) $this->option('force')) {
            return $this->failValidation('force', 'Removing an app instance requires --force.');
        }

        try {
            $response = $this->gatewayDelete($this->apiAppPath($app, '/instances/'.rawurlencode($instance)), [
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderRemovedInstance($instance, $response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderAddedInstance(string $app, string $instance, array $response): int
    {
        $payload = $this->instanceFromGatewayResponse($response);
        $name = $payload === null ? $instance : $this->instanceString($payload, 'name');
        $appName = $payload !== null && ($payload['app'] ?? null) !== null
            ? $this->instanceString($payload, 'app')
            : $app;

        $this->line("Added instance '{$name}' to app '{$appName}'.");

        if ($payload !== null) {
            $this->line('  driver: '.$this->instanceString($payload, 'driver'));

            $extensions = $this->extensionsLabel($payload);

            if ($extensions !== '—') {
                $this->line("  extensions: {$extensions}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderRemovedInstance(string $instance, array $response): int
    {
        $result = $this->successData($response)['result'] ?? null;
        $name = is_array($result) && is_string($result['instance'] ?? null) && $result['instance'] !== ''
            ? $result['instance']
            : $instance;

        $this->line("Removed instance '{$name}'.");

        return self::SUCCESS;
    }

    private function appSelector(): string|int
    {
        $argument = $this->stringArgument('app');
        $option = $this->stringOption('app');

        if ($argument !== null && $option !== null && $argument !== $option) {
            return $this->failValidation('app', 'The [app] argument and --app option must match when both are provided.');
        }

        $app = $option ?? $argument;

        if ($app === null) {
            return $this->failValidation('app', 'App is required.');
        }

        return $app;
    }

    /**
     * @return array<string, mixed>
     */
    private function addPayload(string $instance): array
    {
        return $this->filledPayload([
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
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filledPayload(array $payload): array
    {
        return array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @return list<string>
     */
    private function phpExtensions(): array
    {
        $extensions = $this->option('php-extension');

        if (! is_array($extensions)) {
            return [];
        }

        return array_values(array_filter($extensions, is_string(...)));
    }
}
