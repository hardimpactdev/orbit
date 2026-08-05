<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\PromptsForGatewayRegistryEntities;
use App\Commands\Concerns\RendersShowDetails;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\Apps\AppShowPlacementRows;

use function Laravel\Prompts\table;

final class AppShowCommand extends GatewayCommand
{
    use PromptsForGatewayRegistryEntities;
    use RendersShowDetails;
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'app:show {app? : App name or hostname to inspect} {--json}';

    #[\Override]
    protected $description = 'Show one app from the gateway registry.';

    public function handle(): int
    {
        $selector = $this->resolveAppSelector();

        if (is_int($selector)) {
            return $selector;
        }

        $app = rawurlencode($selector);

        try {
            $response = $this->gatewayGet("/api/apps/{$app}");
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $app = $this->appFromGatewayResponse($response);

        if ($app === null) {
            return $this->renderFailure('gateway_unavailable', 'Gateway response missing required app data.');
        }

        $this->renderApp($app, $this->detailsFromGatewayResponse($response));

        return self::SUCCESS;
    }

    private function resolveAppSelector(): string|int
    {
        $selector = $this->stringArgument('app');

        if ($selector !== null) {
            return $selector;
        }

        if ($this->canPromptForRegistrySelection()) {
            return $this->promptForVisibleApp();
        }

        return $this->renderFailure('validation_failed', 'The app argument is required.', ['field' => 'app']);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    private function appFromGatewayResponse(array $response): ?array
    {
        $app = $this->registrySuccessData($response)['app'] ?? null;

        return $this->associativeArray($app);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function detailsFromGatewayResponse(array $response): array
    {
        $details = $this->registrySuccessData($response)['details'] ?? [];

        return $this->associativeArray($details) ?? [];
    }

    /**
     * @param  array<string, mixed>  $app
     * @param  array<string, mixed>  $details
     */
    private function renderApp(array $app, array $details): void
    {
        $name = is_scalar($app['name'] ?? null) ? (string) $app['name'] : 'unknown';
        $placements = app(AppShowPlacementRows::class);

        $this->renderShowDetails("App: {$name}", [
            'Repository' => $app['repository'] ?? null,
            'PHP' => $app['php_version'] ?? null,
            'Processes' => $this->nameLabels($details['processes'] ?? []),
            'Routes' => $this->routeLabels($details['routes'] ?? []),
            'App deps' => $placements->dependencyLabel($app),
        ]);

        $rows = $placements->forApp($app, $details);

        if ($rows === []) {
            $this->line('No instances found.');

            return;
        }

        table(headers: ['NAME', 'DRIVER', 'NODE', 'URL', 'APP DEPS'], rows: $rows);
    }

    /**
     * @return list<string>
     */
    private function nameLabels(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $labels = [];

        foreach ($items as $item) {
            if (is_string($item) && $item !== '') {
                $labels[] = $item;

                continue;
            }

            if (is_array($item) && is_string($item['name'] ?? null) && $item['name'] !== '') {
                $instance = is_string($item['instance'] ?? null) ? $item['instance'] : null;
                $labels[] = $instance === null || $instance === '' ? $item['name'] : "{$item['name']} ({$instance})";
            }
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    private function routeLabels(mixed $routes): array
    {
        if (! is_array($routes)) {
            return [];
        }

        $labels = [];

        foreach ($routes as $route) {
            if (is_array($route) && is_string($route['host'] ?? null) && $route['host'] !== '') {
                $labels[] = $route['host'];
            }
        }

        return $labels;
    }
}
