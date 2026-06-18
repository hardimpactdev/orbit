<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class AppEnvCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app:env
        {action? : Action to perform (list|set|render)}
        {app? : App name or hostname}
        {--app= : App name or hostname}
        {--instance= : App instance name}
        {--key= : Env key for set}
        {--value= : Env value for set}
        {--secret : Mark value as secret (not supported in this slice)}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'List, set, or render app instance environment values.';

    public function handle(): int
    {
        $action = $this->stringArgument('action');

        if ($action === null) {
            return $this->failValidation('action', 'Action is required.');
        }

        if (! in_array($action, ['list', 'set', 'render'], true)) {
            return $this->renderFailure(
                'validation_failed',
                'Action must be one of: list, set, render.',
                ['field' => 'action', 'allowed' => ['list', 'set', 'render']],
            );
        }

        if ((bool) $this->option('secret')) {
            return $this->failValidation('secret', 'Secret env writes are not supported in this slice.');
        }

        $app = $this->appSelector();

        if (is_int($app)) {
            return $app;
        }

        $instance = $this->stringOption('instance');

        if ($instance === null) {
            return $this->failValidation('instance', 'The --instance option is required.');
        }

        return match ($action) {
            'list' => $this->listEnv($app, $instance),
            'set' => $this->setEnv($app, $instance),
            'render' => $this->renderEnv($app, $instance),
        };
    }

    private function listEnv(string $app, string $instance): int
    {
        try {
            $response = $this->gatewayGet($this->envPath($app, $instance));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $variables = $this->variablesFromGatewayResponse($response);

        if ($variables === []) {
            $this->line('No environment values found.');

            return self::SUCCESS;
        }

        table(
            headers: ['KEY', 'VALUE'],
            rows: array_map(fn (array $variable): array => [
                $this->variableString($variable, 'key'),
                $this->variableString($variable, 'value'),
            ], $variables),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function variablesFromGatewayResponse(array $response): array
    {
        $variables = $response['success']['data']['variables'] ?? null;

        if (! is_array($variables)) {
            return [];
        }

        return array_values(array_filter($variables, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $variable
     */
    private function variableString(array $variable, string $key): string
    {
        $value = $variable[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }

    private function setEnv(string $app, string $instance): int
    {
        $key = $this->stringOption('key');
        $value = $this->stringOption('value');

        if ($key === null) {
            return $this->failValidation('key', 'The --key option is required.');
        }

        if ($value === null) {
            return $this->failValidation('value', 'The --value option is required.');
        }

        try {
            $response = $this->gatewayPost($this->envPath($app, $instance), [
                'key' => $key,
                'value' => $value,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $savedKey = $this->savedKey($response, $key);
        $savedInstance = $this->savedInstance($response, $instance);

        $this->line("Saved '{$savedKey}' for instance '{$savedInstance}'.");

        return self::SUCCESS;
    }

    private function renderEnv(string $app, string $instance): int
    {
        try {
            $response = $this->gatewayGet($this->envPath($app, $instance).'/render');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $variables = $this->renderedVariables($response);

        if ($variables === []) {
            $this->line('No environment values found.');

            return self::SUCCESS;
        }

        foreach ($variables as $key => $value) {
            $this->line("{$key}={$value}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function savedKey(array $response, string $fallback): string
    {
        $variable = $this->successData($response)['variable'] ?? null;

        if (is_array($variable) && is_string($variable['key'] ?? null) && $variable['key'] !== '') {
            return $variable['key'];
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function savedInstance(array $response, string $fallback): string
    {
        $instance = $this->successData($response)['instance'] ?? null;

        if (is_string($instance) && $instance !== '') {
            return $instance;
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, string>
     */
    private function renderedVariables(array $response): array
    {
        $variables = $this->successData($response)['variables'] ?? null;

        if (! is_array($variables)) {
            return [];
        }

        $rendered = [];

        foreach ($variables as $key => $entry) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $value = is_array($entry) ? ($entry['value'] ?? null) : $entry;
            $rendered[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $rendered;
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

    private function envPath(string $app, string $instance): string
    {
        return $this->apiAppPath($app, '/instances/'.rawurlencode($instance).'/env');
    }
}
