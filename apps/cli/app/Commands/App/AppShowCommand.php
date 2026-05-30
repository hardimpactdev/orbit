<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\PromptsForGatewayRegistryEntities;
use App\Commands\Concerns\RendersShowDetails;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

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

        return is_array($app) ? $app : null;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function detailsFromGatewayResponse(array $response): array
    {
        $details = $this->registrySuccessData($response)['details'] ?? [];

        return is_array($details) ? $details : [];
    }

    /**
     * @param  array<string, mixed>  $app
     * @param  array<string, mixed>  $details
     */
    private function renderApp(array $app, array $details): void
    {
        $name = is_scalar($app['name'] ?? null) ? (string) $app['name'] : 'unknown';

        $this->renderShowDetails("App: {$name}", [
            'Domain' => $this->domainLabel($app, $details),
            'Node' => $this->nodeLabel($details['node'] ?? $app['node'] ?? null),
            'Repository' => $app['repository'] ?? null,
            'PHP' => $app['php_version'] ?? null,
            'Path' => $app['path'] ?? null,
            'Root' => $details['document_root'] ?? $app['root'] ?? null,
            'Workspaces' => $this->nameLabels($details['workspaces'] ?? []),
            'Processes' => $this->nameLabels($details['processes'] ?? []),
            'Routes' => $this->routeLabels($details['routes'] ?? []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $app
     * @param  array<string, mixed>  $details
     */
    private function domainLabel(array $app, array $details): ?string
    {
        if (is_string($details['domain'] ?? null) && $details['domain'] !== '') {
            return $details['domain'];
        }

        $url = is_string($app['url'] ?? null) ? $app['url'] : null;
        $host = $url === null ? null : parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $url;
    }

    private function nodeLabel(mixed $node): string
    {
        if (! is_array($node)) {
            return is_scalar($node) && (string) $node !== '' ? (string) $node : '—';
        }

        $name = is_string($node['name'] ?? null) ? $node['name'] : null;
        $host = is_string($node['host'] ?? null) ? $node['host'] : null;

        if ($name === null || $name === '') {
            return '—';
        }

        return $host === null || $host === '' ? $name : "{$name} ({$host})";
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
                $labels[] = $item['name'];
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
