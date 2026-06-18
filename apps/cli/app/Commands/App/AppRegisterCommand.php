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
    protected $signature = 'app:register
        {name? : App name}
        {--node= : Target app node}
        {--path= : Existing app path on the target node}
        {--root=public : Document root relative to app path}
        {--php-version=8.5 : PHP version}
        {--domain= : Production domain}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Register or re-apply Orbit management for an app.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->failValidation('name', 'App name is required.');
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
            'Registering App',
            [
                ['label' => 'Resolve app configuration', 'doneLabel' => 'Resolved app configuration'],
                ['label' => 'Register app record or adopt app path', 'doneLabel' => 'Registered app record or adopted app path'],
                ['label' => 'Apply and verify app runtime', 'doneLabel' => 'Applied and verified app runtime'],
                ['label' => 'Apply and verify app routing', 'doneLabel' => 'Applied and verified app routing'],
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
            'adopted' => "App '{$name}' adopted",
            'converged' => "App '{$name}' converged",
            default => "App '{$name}' registered",
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
        $app = $this->appData($response);
        $name = (string) ($app['name'] ?? '');
        $node = (string) ($app['node'] ?? '');
        $path = (string) ($app['path'] ?? '');

        return match ($this->action($response)) {
            'adopted' => "App '{$name}' successfully adopted from path '{$path}' on node '{$node}'.",
            'converged' => "App '{$name}' is already converged on node '{$node}'. No changes were needed.",
            default => "App '{$name}' successfully registered on node '{$node}'.",
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function registerApp(string $name): array
    {
        return $this->gatewayPost('/api/apps/register', [
            'name' => $name,
            'node' => $this->stringOption('node'),
            'path' => $this->stringOption('path'),
            'root' => $this->stringOption('root') ?? 'public',
            'php_version' => $this->stringOption('php-version') ?? '8.5',
            'domain' => $this->stringOption('domain'),
        ]);
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

        return is_array($app) ? $app : [];
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
