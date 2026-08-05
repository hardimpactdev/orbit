<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final class WorkspaceEnvCommand extends WorkspaceGatewayCommand
{
    #[\Override]
    protected $signature = 'workspace:env
        {action? : Action to perform (list|set|render)}
        {name? : Workspace name}
        {--instance= : Instance selector (app.instance)}
        {--key= : Env key for set}
        {--value= : Env value for set}
        {--apply : Persist and apply set values to the workspace runtime}
        {--secret : Mark value as secret (not supported in this slice)}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'List, set, or render workspace environment values.';

    public function handle(): int
    {
        $action = $this->stringArgument('action');

        if ($action === null || ! in_array($action, ['list', 'set', 'render'], strict: true)) {
            return $this->failValidation('action', 'Action must be one of: list, set, render.');
        }

        if ((bool) $this->option('secret')) {
            return $this->failValidation('secret', 'Secret env writes are not supported in this slice.');
        }

        if ((bool) $this->option('apply') && $action !== 'set') {
            return $this->failValidation('apply', 'The --apply option is only supported for set.');
        }

        $target = $this->resolveTarget();

        if (is_int($target)) {
            return $target;
        }

        return match ($action) {
            'list' => $this->listEnv($target),
            'set' => $this->setEnv($target),
            'render' => $this->renderEnv($target),
        };
    }

    /**
     * @return array{workspace: string, instance?: string|null}|int
     */
    private function resolveTarget(): array|int
    {
        $name = $this->stringArgument('name');
        $query = $this->appQuery();

        if ($name !== null) {
            return ['workspace' => $name] + $query;
        }

        $hostCwd = $this->hostCwd();

        if ($hostCwd === null) {
            return $this->failValidation('name', 'Workspace name is required outside a registered workspace path.');
        }

        try {
            $response = $this->gatewayGet(
                '/api/workspaces/env/resolve-by-path',
                ['path' => $hostCwd] + $query,
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $data = $this->successData($response);

        if (! is_string($data['workspace'] ?? null) || $data['workspace'] === '') {
            return $this->renderFailure(
                'gateway_unavailable',
                'Gateway response missing required workspace identity.',
            );
        }

        return [
            'workspace' => $data['workspace'],
            'instance' => $this->instanceSelector($data),
        ];
    }

    /**
     * @return array{instance?: string}
     */
    private function appQuery(): array
    {
        $selector = $this->stringOption('instance');

        if ($selector === null) {
            return [];
        }

        return ['instance' => $selector];
    }

    /**
     * @param  array{workspace: string, instance?: string|null}  $target
     */
    private function listEnv(array $target): int
    {
        try {
            $response = $this->gatewayGet($this->targetBasePath($target), $this->targetQuery($target));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $data = $this->successData($response);
        $variables = $this->listedVariables($data);

        if ($variables === []) {
            $this->line('No environment values found.');
            $this->renderTargetOutcome($data);

            return self::SUCCESS;
        }

        table(
            headers: ['KEY', 'VALUE'],
            rows: array_values(array_map(
                static fn (array $variable): array => [
                    is_scalar($variable['key'] ?? null) ? (string) $variable['key'] : '—',
                    is_scalar($variable['value'] ?? null) ? (string) $variable['value'] : '—',
                ],
                $variables,
            )),
        );
        $this->renderTargetOutcome($data);

        return self::SUCCESS;
    }

    /**
     * @param  array{workspace: string, instance?: string|null}  $target
     */
    private function setEnv(array $target): int
    {
        $key = $this->stringOption('key');
        $value = $this->stringOption('value');

        if ($key === null) {
            return $this->failValidation('key', 'The --key option is required.');
        }

        if ($value === null) {
            return $this->failValidation('value', 'The --value option is required.');
        }

        $payload = ['key' => $key, 'value' => $value];

        if ((bool) $this->option('apply')) {
            $payload['apply'] = true;
        }

        try {
            $response = $this->gatewayPost($this->targetPath($target), $payload);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $data = $this->successData($response);
        $workspace = is_string($data['workspace'] ?? null) ? $data['workspace'] : $target['workspace'];
        $this->line(((bool) $this->option('apply') ? 'Applied' : 'Saved')." '{$key}' for workspace '{$workspace}'.");
        $this->renderTargetOutcome($data);

        return self::SUCCESS;
    }

    /**
     * @param  array{workspace: string, instance?: string|null}  $target
     */
    private function renderEnv(array $target): int
    {
        try {
            $response = $this->gatewayGet(
                $this->targetBasePath($target).'/render',
                $this->targetQuery($target),
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $data = $this->successData($response);
        $variables = $this->renderedVariables($data);

        if ($variables === []) {
            $this->line('No environment values found.');
            $this->renderTargetOutcome($data);

            return self::SUCCESS;
        }

        foreach ($variables as $key => $value) {
            $this->line("{$key}={$value}");
        }

        $this->renderTargetOutcome($data);

        return self::SUCCESS;
    }

    /**
     * @param  array{workspace: string, instance?: string|null}  $target
     */
    private function targetPath(array $target): string
    {
        return $this->pathWithQuery(
            $this->targetBasePath($target),
            $this->targetQuery($target),
        );
    }

    /**
     * @param  array{workspace: string, instance?: string|null}  $target
     */
    private function targetBasePath(array $target): string
    {
        return '/api/workspaces/'.rawurlencode($target['workspace']).'/env';
    }

    /**
     * @param  array{workspace: string, instance?: string|null}  $target
     * @return array{instance?: string|null}
     */
    private function targetQuery(array $target): array
    {
        return ['instance' => $target['instance'] ?? null];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderTargetOutcome(array $data): void
    {
        $this->line('Scope: workspace');
        $this->line('App: '.$this->stringValue($data, 'app'));
        $this->line('Instance: '.$this->stringValue($data, 'instance'));
        $this->line('Workspace: '.$this->stringValue($data, 'workspace'));
        $this->line('Path: '.$this->stringValue($data, 'path'));
        $this->line('Stored: '.$this->yesNo($data['stored'] ?? false));
        $this->line('Applied: '.$this->yesNo($data['applied'] ?? false));
        $this->line('Runtime restarted: '.$this->yesNo($data['runtime_restarted'] ?? false));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function instanceSelector(array $data): ?string
    {
        $app = is_string($data['app'] ?? null) ? $data['app'] : null;
        $instance = is_string($data['instance'] ?? null) ? $data['instance'] : null;

        if ($app === null || $instance === null) {
            return null;
        }

        return "{$app}.{$instance}";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function stringValue(array $data, string $key): string
    {
        return is_scalar($data[$key] ?? null) && (string) $data[$key] !== ''
            ? (string) $data[$key]
            : '—';
    }

    private function yesNo(mixed $value): string
    {
        return $value === true ? 'yes' : 'no';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    private function listedVariables(array $data): array
    {
        if (! is_array($data['variables'] ?? null)) {
            return [];
        }

        return array_values(array_filter($data['variables'], is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     *
     * @mago-expect analysis:mixed-assignment
     */
    private function renderedVariables(array $data): array
    {
        if (! is_array($data['variables'] ?? null)) {
            return [];
        }

        $variables = [];

        foreach ($data['variables'] as $key => $entry) {
            if (! is_string($key)) {
                continue;
            }

            $value = is_array($entry) ? $entry['value'] ?? null : $entry;
            $variables[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $variables;
    }
}
