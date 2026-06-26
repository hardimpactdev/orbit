<?php

declare(strict_types=1);

namespace App\Librarian;

final readonly class CommandCatalogGatewayRouteParser
{
    public function __construct(
        private CommandCatalogEndpointNormalizer $normalizer,
        private CommandCatalogPhpClassInspector $classes,
        private CommandCatalogRepositoryPaths $paths,
    ) {}

    /**
     * @return array<string, array{method: string, uri: string, controller: array{class: string, path: string, action: string}|null}>
     */
    public function routesFromSource(string $source): array
    {
        $uses = $this->classes->useStatements($source);
        $matches = [];
        preg_match_all(
            '/Route::(get|post|put|patch|delete)\(\s*([\'"])([^\'"]+)\2\s*,\s*(.*?)\)\s*(?:->|;)/s',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $routes = [];

        foreach ($matches as $match) {
            $method = strtoupper($match[1]);
            $uri = $this->normalizer->normalizeApiUri('/api'.$match[3]);

            $routes[$this->normalizer->endpointKey($method, $uri)] = [
                'method' => $method,
                'uri' => $uri,
                'controller' => $this->routeController($match[4], $uses),
            ];
        }

        ksort($routes);

        return $routes;
    }

    /**
     * @param  array<string, string>  $uses
     * @return array{class: string, path: string, action: string}|null
     */
    private function routeController(string $target, array $uses): ?array
    {
        $target = trim($target);
        $match = [];

        if (preg_match('/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class$/', $target, $match)) {
            return $this->gatewayController($uses[$match[1]] ?? $match[1], '__invoke');
        }

        if (preg_match(
            '/^\[\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]$/',
            $target,
            $match,
        )) {
            return $this->gatewayController($uses[$match[1]] ?? $match[1], $match[2]);
        }

        return null;
    }

    /**
     * @return array{class: string, path: string, action: string}|null
     */
    private function gatewayController(string $class, string $action): ?array
    {
        $path = $this->paths->gatewayClassPath($class);

        if ($path === null) {
            return null;
        }

        return [
            'class' => $class,
            'path' => $path,
            'action' => $action,
        ];
    }
}
