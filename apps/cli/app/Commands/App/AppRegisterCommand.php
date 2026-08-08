<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class AppRegisterCommand extends AppGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'instance:register
        {app? : App name or app.instance selector}
        {--node= : Target instance node}
        {--path= : Existing app path on the target node}
        {--root= : Document root relative to app path (default: existing value or public)}
        {--php-version= : PHP version for this instance (default: existing instance value, then the app creation template, then 8.5)}
        {--domain= : Production domain}
        {--runtime-proxy-transport= : FrankenPHP inner proxy transport (http|https)}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Register or re-apply Orbit management for an app instance.';

    public function handle(): int
    {
        $name = $this->stringArgument('app');

        if ($name === null) {
            return $this->failValidation('app', 'App name is required.');
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->registerApp($name);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRegistrationTree($name);
    }

    private function renderRegistrationTree(string $name): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Registering Instance',
            [
                ['label' => 'Resolve app configuration', 'doneLabel' => 'Resolved app configuration'],
                [
                    'label' => 'Register app and instance or adopt app path',
                    'doneLabel' => 'Registered app and instance or adopted app path',
                ],
                [
                    'label' => 'Apply and verify instance runtime',
                    'doneLabel' => 'Applied and verified instance runtime',
                ],
                [
                    'label' => 'Apply and verify instance routing',
                    'doneLabel' => 'Applied and verified instance routing',
                ],
                ['label' => 'Verify application', 'doneLabel' => 'Verified application'],
            ],
            work: function () use ($name, &$response): array {
                return $response = $this->registerAppForHuman($name);
            },
            doneFooter: function () use ($name, &$response): string {
                return $this->footerFor($name, $response);
            },
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderRegistrationNotes($response);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function footerFor(string $name, array $response): string
    {
        return match ($this->action($response)) {
            'adopted' => "Instance for app '{$name}' adopted",
            'moved' => "Instance for app '{$name}' moved",
            'converged' => "Instance for app '{$name}' converged",
            'partial' => "Instance for app '{$name}' partially enacted",
            default => "Instance for app '{$name}' registered",
        };
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderRegistrationNotes(array $response): void
    {
        $this->line('  '.$this->successLine($response));

        foreach ($this->warnings($response) as $warning) {
            $message = is_string($warning['message'] ?? null) ? trim($warning['message']) : '';

            if ($message === '') {
                continue;
            }

            $this->line("  Warning: {$message}");

            $nextCommand = $warning['next_command'] ?? null;

            if (is_string($nextCommand) && trim($nextCommand) !== '') {
                $this->line('  Retry with: orbit '.trim($nextCommand));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function successLine(array $response): string
    {
        $selector = $this->selectorFor($response);
        $instance = $this->instanceData($response);
        $node = (string) ($instance['node'] ?? '');
        $path = (string) ($instance['path'] ?? '');

        return match ($this->action($response)) {
            'adopted' => "Instance '{$selector}' successfully adopted from path '{$path}' on node '{$node}'.",
            'moved' => "Instance '{$selector}' successfully moved to path '{$path}' on node '{$node}'.",
            'converged' => "Instance '{$selector}' is already converged on node '{$node}'. No changes were needed.",
            'partial' => "Instance '{$selector}' is registered on node '{$node}', but proxy enactment is incomplete.",
            default => "Instance '{$selector}' successfully registered on node '{$node}'.",
        };
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function selectorFor(array $response): string
    {
        $appName = (string) ($this->appData($response)['name'] ?? '');
        $instanceName = (string) ($this->instanceData($response)['name'] ?? '');

        if ($appName === '' || $instanceName === '') {
            return $appName !== '' ? $appName : (string) $this->stringArgument('app');
        }

        return "{$appName}.{$instanceName}";
    }

    /**
     * @return array<string, mixed>
     */
    private function registerApp(string $name): array
    {
        $payload = [
            'name' => $name,
            'node' => $this->stringOption('node'),
            'path' => $this->stringOption('path'),
            'domain' => $this->stringOption('domain'),
        ];

        foreach ([
            'root' => 'root',
            'php-version' => 'php_version',
            'runtime-proxy-transport' => 'runtime_proxy_transport',
        ] as $option => $key) {
            $value = $this->stringOption($option);

            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        return $this->gatewayPost('/api/instances/register', $payload);
    }

    /**
     * Run the registration inside the progress tree, re-throwing gateway failures
     * with their operator-facing message so the failed footer renders the
     * documented prose rather than a JSON envelope.
     *
     * @return array<string, mixed>
     */
    private function registerAppForHuman(string $name): array
    {
        try {
            return $this->registerApp($name);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function action(array $response): string
    {
        $result = $this->successData($response)['result'] ?? null;

        if (is_array($result) && is_string($result['action'] ?? null)) {
            return $result['action'];
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function appData(array $response): array
    {
        $app = $this->successData($response)['app'] ?? null;

        return $this->associativeArray($app) ?? [];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function instanceData(array $response): array
    {
        $instance = $this->successData($response)['instance'] ?? null;

        return $this->associativeArray($instance) ?? [];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function warnings(array $response): array
    {
        $warnings = $response['success']['meta']['warnings'] ?? null;

        if (! is_array($warnings)) {
            return [];
        }

        $entries = [];

        foreach ($warnings as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
