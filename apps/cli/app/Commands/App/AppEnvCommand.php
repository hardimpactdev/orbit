<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

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

        return $this->renderSuccess($response);
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

        return $this->renderSuccess($response);
    }

    private function renderEnv(string $app, string $instance): int
    {
        try {
            $response = $this->gatewayGet($this->envPath($app, $instance).'/render');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
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
