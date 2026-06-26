<?php

declare(strict_types=1);

namespace App\Librarian;

final class CommandCatalogGatewayRouteIndex
{
    /** @var array<string, array{method: string, uri: string, controller: array{class: string, path: string, action: string}|null}>|null */
    private ?array $routesByEndpoint = null;

    public function __construct(
        private readonly CommandCatalogRepositoryPaths $paths,
        private readonly CommandCatalogGatewayRouteParser $parser,
        private readonly CommandCatalogEndpointNormalizer $normalizer,
    ) {}

    /**
     * @return array{method: string, uri: string, controller: array{class: string, path: string, action: string}|null}|null
     */
    public function routeForEndpoint(string $method, string $uri): ?array
    {
        return $this->routesByEndpoint()[$this->normalizer->endpointKey($method, $uri)] ?? null;
    }

    /**
     * @return array<string, array{method: string, uri: string, controller: array{class: string, path: string, action: string}|null}>
     */
    private function routesByEndpoint(): array
    {
        if ($this->routesByEndpoint !== null) {
            return $this->routesByEndpoint;
        }

        $source = file_get_contents($this->paths->absolutePath('apps/gateway/routes/api.php'));

        if ($source === false) {
            return $this->routesByEndpoint = [];
        }

        return $this->routesByEndpoint = $this->parser->routesFromSource($source);
    }
}
