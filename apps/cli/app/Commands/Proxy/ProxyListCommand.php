<?php

declare(strict_types=1);

namespace App\Commands\Proxy;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class ProxyListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'proxy:list
        {--node= : Filter by serving node}
        {--filter=all : Filter routes by all, app, workspace, gateway, tool, custom, or redirect}
        {--json}';

    #[\Override]
    protected $description = 'List proxy routes tracked by gateway intent.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/proxy-routes', $this->filledQuery([
                'node' => $this->stringOption('node'),
                'filter' => $this->stringOption('filter'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $routes = $this->routesFromGatewayResponse($response);

        if ($routes === []) {
            $this->line($this->emptyState());

            return self::SUCCESS;
        }

        table(
            headers: ['DOMAIN', 'KIND', 'OWNER', 'NODE', 'TARGET', 'TLS', 'STATUS'],
            rows: array_map(fn (array $route): array => [
                $this->routeString($route, 'domain'),
                $this->routeString($route, 'kind'),
                $this->owner($route),
                $this->routeString($route, 'node'),
                $this->nested($route, 'target', 'value'),
                $this->nested($route, 'tls', 'managed_by'),
                $this->routeString($route, 'status'),
            ], $routes),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function routesFromGatewayResponse(array $response): array
    {
        $routes = $response['success']['data']['routes'] ?? null;

        if (! is_array($routes)) {
            return [];
        }

        return array_values(array_filter($routes, is_array(...)));
    }

    private function emptyState(): string
    {
        $filter = $this->stringOption('filter') ?? 'all';
        $node = $this->stringOption('node');

        $scope = $filter === 'all'
            ? 'No proxy routes found'
            : "No {$filter} proxy routes found";

        if ($node !== null) {
            $scope .= " on node {$node}";
        }

        return $scope.'.';
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function owner(array $route): string
    {
        $owner = $route['owner'] ?? null;

        if (! is_array($owner)) {
            return '—';
        }

        $type = $owner['type'] ?? null;
        $name = $owner['name'] ?? null;

        if (! is_scalar($type) || (string) $type === '') {
            return '—';
        }

        if (is_scalar($name) && (string) $name !== '') {
            return (string) $type.':'.(string) $name;
        }

        return (string) $type;
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function nested(array $route, string $key, string $childKey): string
    {
        $value = $route[$key] ?? null;

        if (! is_array($value)) {
            return '—';
        }

        $child = $value[$childKey] ?? null;

        if (is_scalar($child) && (string) $child !== '') {
            return (string) $child;
        }

        return '—';
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function routeString(array $route, string $key): string
    {
        $value = $route[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
