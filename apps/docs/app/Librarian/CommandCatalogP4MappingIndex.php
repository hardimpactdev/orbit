<?php

declare(strict_types=1);

namespace App\Librarian;

final readonly class CommandCatalogP4MappingIndex
{
    public function __construct(
        private CommandCatalogCliEndpointIndex $cliEndpoints,
        private CommandCatalogSdkRequestIndex $sdkRequests,
        private CommandCatalogGatewayRouteIndex $gatewayRoutes,
        private CommandCatalogGatewayPermissionIndex $permissions,
    ) {}

    /**
     * @return array{
     *     sdk_request: array{class: string, path: string}|null,
     *     gateway_route: array{method: string, uri: string}|null,
     *     gateway_controller: array{class: string, path: string, action: string}|null,
     *     authorization_permission: list<string>|null,
     *     response_dto: array{class: string, path: string}|null,
     * }
     */
    public function forCommand(CliCommand $command): array
    {
        $endpoint = $this->cliEndpoints->primaryEndpointFor($command);

        if ($endpoint === null) {
            return $this->emptyMapping();
        }

        $sdkRequest = $this->sdkRequests->requestForEndpoint($endpoint['method'], $endpoint['uri']);
        $gatewayRoute = $this->gatewayRoutes->routeForEndpoint($endpoint['method'], $endpoint['uri']);
        $gatewayController = $gatewayRoute['controller'] ?? null;

        return [
            'sdk_request' => $this->sdkRequestEntry($sdkRequest),
            'gateway_route' => $this->gatewayRouteEntry($gatewayRoute),
            'gateway_controller' => $gatewayController,
            'authorization_permission' => $gatewayController === null
                ? null
                : $this->permissions->authorizationPermissions(
                    $gatewayController['path'],
                    $gatewayController['action'],
                ),
            'response_dto' => $sdkRequest['response_dto'] ?? null,
        ];
    }

    /**
     * @return array{
     *     sdk_request: null,
     *     gateway_route: null,
     *     gateway_controller: null,
     *     authorization_permission: null,
     *     response_dto: null,
     * }
     */
    private function emptyMapping(): array
    {
        return [
            'sdk_request' => null,
            'gateway_route' => null,
            'gateway_controller' => null,
            'authorization_permission' => null,
            'response_dto' => null,
        ];
    }

    /**
     * @param  array{class: string, path: string, response_dto: array{class: string, path: string}|null}|null  $request
     * @return array{class: string, path: string}|null
     */
    private function sdkRequestEntry(?array $request): ?array
    {
        if ($request === null) {
            return null;
        }

        return [
            'class' => $request['class'],
            'path' => $request['path'],
        ];
    }

    /**
     * @param  array{method: string, uri: string, controller: array{class: string, path: string, action: string}|null}|null  $route
     * @return array{method: string, uri: string}|null
     */
    private function gatewayRouteEntry(?array $route): ?array
    {
        if ($route === null) {
            return null;
        }

        return [
            'method' => $route['method'],
            'uri' => $route['uri'],
        ];
    }
}
