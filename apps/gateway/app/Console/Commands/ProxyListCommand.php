<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Proxy\ListProxyRoutesRequest;
use App\Http\Gateway\Responses\Proxy\ProxyRouteListResponse;
use App\Services\Proxy\ProxyRouteQuery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\table;

#[Signature('proxy:list
    {--node= : Filter by serving node}
    {--filter=all : Filter routes by all, app, workspace, gateway, websocket, tool, custom, or redirect}
    {--json : Output JSON}')]
#[Description('List proxy routes tracked by gateway intent')]
class ProxyListCommand extends Command
{
    public function handle(ProxyRouteQuery $routes): int
    {
        $filter = $this->stringOption('filter') ?? 'all';
        $node = $this->stringOption('node');

        if (! in_array($filter, ProxyRouteQuery::AllowedFilters, true)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'The selected proxy route filter is invalid.',
                meta: [
                    'field' => 'filter',
                    'allowed' => ProxyRouteQuery::AllowedFilters,
                ],
            );
        }

        if ($node !== null && str_contains($node, ',')) {
            return $this->failCommand(
                code: 'validation_failed',
                message: "Unknown node: '{$node}'.",
                meta: [
                    'field' => 'node',
                    'value' => $node,
                ],
            );
        }

        try {
            $result = $this->fetchRoutes($routes, $filter, $node);
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to list proxy routes.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to list proxy routes.',
                meta: [],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['routes' => $result['routes']], $result['meta']);
        }

        $this->renderHuman($result['routes'], $result['meta']);

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     routes: list<array<string, mixed>>,
     *     meta: array<string, mixed>,
     * }
     */
    private function fetchRoutes(ProxyRouteQuery $routes, string $filter, ?string $node): array
    {
        if ((bool) config('orbit.is_gateway', false)) {
            return $routes->list(filter: $filter, node: $node);
        }

        /** @var ProxyRouteListResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new ListProxyRoutesRequest(filter: $filter, node: $node))
            ->dto();

        return [
            'routes' => $dto->routes,
            'meta' => $dto->meta,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $routes
     * @param  array<string, mixed>  $meta
     */
    private function renderHuman(array $routes, array $meta): void
    {
        if ($routes === []) {
            $filter = (string) ($meta['filter'] ?? 'all');
            $node = is_string($meta['node'] ?? null) ? $meta['node'] : 'all nodes';

            $this->line("No proxy routes found for filter '{$filter}' on {$node}.");

            return;
        }

        $rows = array_map(fn (array $route): array => [
            $route['domain'] ?? '',
            $route['kind'] ?? '',
            $this->ownerLabel($route),
            $route['node'] ?? '',
            $this->targetLabel($route),
            $route['tls']['managed_by'] ?? '',
            $route['status'] ?? '',
        ], $routes);

        table(['Domain', 'Kind', 'Owner', 'Node', 'Target', 'TLS', 'Status'], $rows);
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function ownerLabel(array $route): string
    {
        $owner = is_array($route['owner'] ?? null) ? $route['owner'] : [];
        $type = is_string($owner['type'] ?? null) ? $owner['type'] : 'custom';
        $name = is_string($owner['name'] ?? null) ? $owner['name'] : null;

        return $name === null || $name === '' ? $type : "{$type}:{$name}";
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function targetLabel(array $route): string
    {
        $target = is_array($route['target'] ?? null) ? $route['target'] : [];
        $type = is_string($target['type'] ?? null) ? $target['type'] : '';
        $value = is_string($target['value'] ?? null) ? $target['value'] : '';

        return $value === '' ? $type : "{$type}:{$value}";
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function jsonSuccess(array $data, array $meta): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }
}
