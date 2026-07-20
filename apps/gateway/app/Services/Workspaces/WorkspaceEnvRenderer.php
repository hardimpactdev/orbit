<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Project;
use App\Models\Workspace;
use App\Models\WorkspaceEnvVariable;
use App\Services\Apps\LaravelViteDevServerEnvironment;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class WorkspaceEnvRenderer
{
    public function __construct(
        private LaravelViteDevServerEnvironment $vite,
        private WorkspacePlacement $placement,
    ) {}

    /**
     * @return list<array{key: string, value: string|null, secret: bool}>
     */
    public function variables(Workspace $workspace): array
    {
        $workspace->loadMissing('envVariables');

        return array_values(
            $workspace
                ->envVariables
                ->map($this->variablePayload(...))
                ->values()
                ->all(),
        );
    }

    public function set(Workspace $workspace, string $key, string $value): WorkspaceEnvVariable
    {
        return $workspace->envVariables()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'secret' => false],
        );
    }

    /**
     * @return array<string, array{value: string|null, secret: bool, source: string}>
     */
    public function render(Workspace $workspace): array
    {
        $workspace->loadMissing([
            'app',
            'appInstance',
            'envVariables',
            'databaseConnectionTargets.connection',
        ]);

        $env = [];
        $app = $workspace->app;
        $node = $this->placement->nodeForWorkspace($workspace);

        if ($app instanceof Project && $node instanceof Node) {
            foreach ($this->vite->shellVariables($app, $node, $workspace) as $key => $value) {
                $env[$key] = [
                    'value' => $value,
                    'secret' => false,
                    'source' => 'orbit',
                ];
            }
        }

        foreach ($workspace->envVariables as $variable) {
            $env[$variable->key] = [
                'value' => $variable->secret ? null : $variable->value,
                'secret' => $variable->secret,
                'source' => 'workspace',
            ];
        }

        foreach ($workspace->databaseConnectionTargets as $target) {
            /** @var DatabaseConnection|null $connection */
            $connection = $target->getRelation('connection');

            if (! $connection instanceof DatabaseConnection) {
                continue;
            }

            foreach ($this->databaseVariables($connection, $target->env_prefix) as $key => $entry) {
                $env[$key] = $entry;
            }
        }

        ksort($env);

        return $env;
    }

    /**
     * @return array<string, string>
     */
    public function applicableValues(Workspace $workspace): array
    {
        $values = [];

        foreach ($this->render($workspace) as $key => $entry) {
            if ($entry['secret'] || ! is_string($entry['value'])) {
                continue;
            }

            $values[$key] = $entry['value'];
        }

        return $values;
    }

    /**
     * @return array{key: string, value: string|null, secret: bool}
     */
    public function variablePayload(WorkspaceEnvVariable $variable): array
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
            'value' => null,
            'secret' =>
                is_string($connection->credentials['password'] ?? null) && $connection->credentials['password'] !== '',
            'source' => 'database',
        ];

        return $payload;
    }
}
