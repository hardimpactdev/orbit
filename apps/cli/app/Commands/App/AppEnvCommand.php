<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

use function Laravel\Prompts\table;

final class AppEnvCommand extends AppGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'instance:env
        {action? : Action to perform (list|set|render)}
        {instance? : app.instance selector}
        {--key= : Env key for set}
        {--value= : Env value for set}
        {--apply : Persist and apply set values to the remote instance runtime}
        {--secret : Mark value as secret (not supported in this slice)}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'List, set, or render instance environment values.';

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

        if ((bool) $this->option('apply') && $action !== 'set') {
            return $this->failValidation('apply', 'The --apply option is only supported for set.');
        }

        $target = $this->instanceSelector();

        if (is_int($target)) {
            return $target;
        }

        return match ($action) {
            'list' => $this->listEnv($target['app'], $target['instance']),
            'set' => $this->setEnv($target['app'], $target['instance']),
            'render' => $this->renderEnv($target['app'], $target['instance']),
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
        $data = $this->successData($response);

        if ($variables === []) {
            $this->line('No environment values found.');
            $this->renderTargetOutcome($data);

            return self::SUCCESS;
        }

        table(
            headers: ['KEY', 'VALUE'],
            rows: array_map(fn (array $variable): array => [
                $this->variableString($variable, 'key'),
                $this->variableString($variable, 'value'),
            ], $variables),
        );
        $this->renderTargetOutcome($data);

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

        if ($this->wantsJson()) {
            try {
                $response = $this->setEnvOnGateway($app, $instance, $key, $value);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        if ($this->option('apply')) {
            return $this->renderSetApplyTree($app, $instance, $key, $value);
        }

        try {
            $response = $this->setEnvOnGateway($app, $instance, $key, $value);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $savedKey = $this->savedKey($response, $key);
        $savedInstance = $this->savedInstance($response, $instance);
        $data = $this->successData($response);
        $savedProject = is_string($data['app'] ?? null) ? $data['app'] : $app;

        $this->line("Saved '{$savedKey}' for instance '{$savedProject}.{$savedInstance}'.");
        $this->renderTargetOutcome($data);

        return self::SUCCESS;
    }

    private function renderSetApplyTree(string $app, string $instance, string $key, string $value): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Applying Instance Env',
            [
                ['label' => 'Save env value in gateway state', 'doneLabel' => 'Saved env value in gateway state'],
                ['label' => 'Update remote .env file', 'doneLabel' => 'Updated remote .env file'],
                ['label' => 'Clear Laravel caches', 'doneLabel' => 'Cleared Laravel caches'],
                [
                    'label' => 'Reapply instance runtime container',
                    'doneLabel' => 'Reapplied instance runtime container',
                ],
            ],
            work: function () use ($app, $instance, $key, $value, &$response): array {
                return $response = $this->setEnvOnGatewayForHuman($app, $instance, $key, $value);
            },
            doneFooter: function () use (&$response, $key, $instance): string {
                return $this->applyFooterFor($response, $key, $instance);
            },
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderApplyNotes($response, $key, $instance);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function setEnvOnGateway(string $app, string $instance, string $key, string $value): array
    {
        return $this->gatewayPost($this->envPath($app, $instance), $this->setPayload($key, $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function setEnvOnGatewayForHuman(string $app, string $instance, string $key, string $value): array
    {
        try {
            return $this->setEnvOnGateway($app, $instance, $key, $value);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @return array{key: string, value: string, apply?: bool}
     */
    private function setPayload(string $key, string $value): array
    {
        $payload = [
            'key' => $key,
            'value' => $value,
        ];

        if ($this->option('apply')) {
            $payload['apply'] = true;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function applyFooterFor(array $response, string $key, string $instance): string
    {
        $savedKey = $this->savedKey($response, $key);
        $savedInstance = $this->savedInstance($response, $instance);

        return "Applied '{$savedKey}' for instance '{$savedInstance}'";
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderApplyNotes(array $response, string $key, string $instance): void
    {
        $savedKey = $this->savedKey($response, $key);
        $savedInstance = $this->savedInstance($response, $instance);
        $apply = $this->successData($response)['apply'] ?? null;

        $this->line("  Saved and applied '{$savedKey}' for instance '{$savedInstance}'.");

        if (! is_array($apply)) {
            return;
        }

        $envPath = $apply['env_path'] ?? null;

        if (is_string($envPath) && $envPath !== '') {
            $this->line("  Updated '{$envPath}'.");
        }

        $runtimeOutcome = $apply['runtime_outcome'] ?? null;

        if (is_string($runtimeOutcome) && $runtimeOutcome !== '') {
            $this->line("  Runtime container outcome: {$runtimeOutcome}.");
        }

        $this->renderTargetOutcome($this->successData($response), prefix: '  ');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderTargetOutcome(array $data, string $prefix = ''): void
    {
        $path = is_string($data['path'] ?? null) ? $data['path'] : '—';

        $this->line("{$prefix}Scope: instance");
        $this->line("{$prefix}App: ".(is_string($data['app'] ?? null) ? $data['app'] : '—'));
        $this->line("{$prefix}Instance: ".(is_string($data['instance'] ?? null) ? $data['instance'] : '—'));
        $this->line("{$prefix}Workspace: —");
        $this->line("{$prefix}Path: {$path}");
        $this->line("{$prefix}Stored: ".$this->yesNo($data['stored'] ?? false));
        $this->line("{$prefix}Applied: ".$this->yesNo($data['applied'] ?? false));
        $this->line("{$prefix}Runtime restarted: ".$this->yesNo($data['runtime_restarted'] ?? false));
    }

    private function yesNo(mixed $value): string
    {
        return $value === true ? 'yes' : 'no';
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
        $data = $this->successData($response);

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

            $value = is_array($entry) ? $entry['value'] ?? null : $entry;
            $rendered[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $rendered;
    }

    /**
     * @return array{app: string, instance: string}|int
     */
    private function instanceSelector(): array|int
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

    private function envPath(string $app, string $instance): string
    {
        return $this->apiProjectPath($app, '/instances/'.rawurlencode($instance).'/env');
    }
}
