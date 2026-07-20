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
        {project? : Project name}
        {--node= : Target instance node}
        {--path= : Existing project path on the target node}
        {--root=public : Document root relative to project path}
        {--php-version=8.5 : PHP version}
        {--domain= : Production domain}
        {--runtime-proxy-transport= : FrankenPHP inner proxy transport (http|https)}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Register or re-apply Orbit management for a project instance.';

    public function handle(): int
    {
        $name = $this->stringArgument('project');

        if ($name === null) {
            return $this->failValidation('project', 'Project name is required.');
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
                ['label' => 'Resolve project configuration', 'doneLabel' => 'Resolved project configuration'],
                [
                    'label' => 'Register project and instance or adopt project path',
                    'doneLabel' => 'Registered project and instance or adopted project path',
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
            'adopted' => "Instance for project '{$name}' adopted",
            'converged' => "Instance for project '{$name}' converged",
            'partial' => "Instance for project '{$name}' partially enacted",
            default => "Instance for project '{$name}' registered",
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
        $project = $this->projectData($response);
        $name = (string) ($project['name'] ?? '');
        $instance = $this->instanceData($response);
        $node = (string) ($instance['node'] ?? '');
        $path = (string) ($instance['path'] ?? '');

        return match ($this->action($response)) {
            'adopted' => "Instance for project '{$name}' successfully adopted from path '{$path}' on node '{$node}'.",
            'converged'
                => "Instance for project '{$name}' is already converged on node '{$node}'. No changes were needed.",
            'partial'
                => "Instance for project '{$name}' is registered on node '{$node}', but proxy enactment is incomplete.",
            default => "Instance for project '{$name}' successfully registered on node '{$node}'.",
        };
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
            'root' => $this->stringOption('root') ?? 'public',
            'php_version' => $this->stringOption('php-version') ?? '8.5',
            'domain' => $this->stringOption('domain'),
        ];

        $runtimeProxyTransport = $this->stringOption('runtime-proxy-transport');

        if ($runtimeProxyTransport !== null) {
            $payload['runtime_proxy_transport'] = $runtimeProxyTransport;
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
    private function projectData(array $response): array
    {
        $project = $this->successData($response)['project'] ?? null;

        return $this->associativeArray($project) ?? [];
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
