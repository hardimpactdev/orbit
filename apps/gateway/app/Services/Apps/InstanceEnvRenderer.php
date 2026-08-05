<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\InstanceEnvVariable;
use App\Models\Node;
use App\Services\Workspaces\WorkspacePlacement;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class InstanceEnvRenderer
{
    public function __construct(
        private LaravelViteDevServerEnvironment $vite,
        private AppRuntimeContainerRenderer $runtimeRenderer,
        private WorkspacePlacement $placement,
    ) {}

    /**
     * @return list<array{key: string, value: string|null, secret: bool}>
     */
    public function variables(Instance $instance): array
    {
        $instance->loadMissing('envVariables');

        /** @var list<array{key: string, value: string|null, secret: bool}> $payloads */
        $payloads = [];

        $variables = $instance->envVariables;
        if (! $variables instanceof \Illuminate\Database\Eloquent\Collection) {
            return [];
        }

        foreach ($variables->sortBy('key') as $variable) {
            if (! $variable instanceof InstanceEnvVariable) {
                continue;
            }

            $payloads[] = $this->variablePayload($variable);
        }

        return $payloads;
    }

    public function set(Instance $instance, string $key, string $value): InstanceEnvVariable
    {
        return $instance->envVariables()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'secret' => false],
        );
    }

    /**
     * @return array<string, array{value: string|null, secret: bool, source: string}>
     */
    public function render(Instance $instance): array
    {
        $entries = $this->renderEntries($instance);

        foreach ($entries as $key => $entry) {
            if (! $entry['secret']) {
                continue;
            }

            $entries[$key]['value'] = null;
        }

        return $entries;
    }

    /**
     * @return array<string, string>
     */
    public function applicableValues(Instance $instance): array
    {
        $values = [];

        foreach ($this->renderEntries($instance) as $key => $entry) {
            if (! is_string($entry['value'])) {
                continue;
            }

            $values[$key] = $entry['value'];
        }

        return $values;
    }

    /**
     * @return array<string, array{value: string|null, secret: bool, source: string}>
     */
    private function renderEntries(Instance $instance): array
    {
        $instance->loadMissing(['app.node', 'envVariables', 'databaseConnectionTargets.connection']);

        $env = [];
        $sourceApp = $instance->app;
        $app = $this->runtimeRenderer->runtimeAppForInstance($sourceApp, $instance);
        $domain = $this->placement->instanceUrlHost($instance, $sourceApp);

        if ($domain !== '') {
            $app->domain = $domain;
        }

        $node = $app->node ?? null;

        if ($node instanceof Node) {
            foreach ($this->vite->shellVariables($app, $node) as $key => $value) {
                $env[$key] = [
                    'value' => $value,
                    'secret' => false,
                    'source' => 'orbit',
                ];
            }
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, InstanceEnvVariable> $envVariables */
        $envVariables = $instance->envVariables;

        foreach ($envVariables as $variable) {
            $key = $variable->key;
            $value = $variable->value;

            if (! is_string($key) || ! is_string($value)) {
                continue;
            }

            $env[$key] = [
                'value' => $value,
                'secret' => (bool) $variable->secret,
                'source' => 'instance',
            ];
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, DatabaseConnectionTarget> $targets */
        $targets = $instance->databaseConnectionTargets;

        foreach ($targets as $target) {
            if (! $target instanceof DatabaseConnectionTarget) {
                continue;
            }

            /** @var DatabaseConnection|null $connection */
            $connection = $target->getRelation('connection');

            if (! $connection instanceof DatabaseConnection) {
                continue;
            }

            $prefix = $target->env_prefix;

            foreach ($this->databaseVariables($connection, $prefix) as $key => $entry) {
                $env[$key] = $entry;
            }
        }

        ksort($env);

        return $env;
    }

    /**
     * @return array{key: string, value: string|null, secret: bool}
     */
    public function variablePayload(InstanceEnvVariable $variable): array
    {
        return [
            'key' => $variable->key,
            'value' => $variable->secret ? null : $variable->value,
            'secret' => $variable->secret,
        ];
    }

    /**
     * @return array<string, array{value: string|null, secret: bool, source: string}>
     */
    private function databaseVariables(DatabaseConnection $connection, string $prefix): array
    {
        $prefix = strtoupper($prefix);
        $credentials = $connection->credentials;
        $password = is_array($credentials) && is_string($credentials['password'] ?? null)
            ? $credentials['password']
            : null;

        $values = [
            "{$prefix}_CONNECTION" => $connection->driver,
            "{$prefix}_HOST" => $connection->host,
            "{$prefix}_PORT" => $connection->port === null ? null : (string) $connection->port,
            "{$prefix}_DATABASE" => $connection->database,
            "{$prefix}_USERNAME" => $connection->username,
        ];

        $payload = [];

        foreach ($values as $key => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $payload[$key] = [
                'value' => $value,
                'secret' => false,
                'source' => 'database',
            ];
        }

        $payload["{$prefix}_PASSWORD"] = [
            'value' => is_string($password) ? $password : null,
            'secret' => is_string($password) && $password !== '',
            'source' => 'database',
        ];

        return $payload;
    }
}
