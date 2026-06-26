<?php

declare(strict_types=1);

namespace App\Librarian;

final readonly class CommandCatalogSdkRequestParser
{
    public function __construct(
        private CommandCatalogEndpointNormalizer $normalizer,
        private CommandCatalogPhpClassInspector $classes,
        private CommandCatalogRepositoryPaths $paths,
    ) {}

    /**
     * @return array{
     *     class: string,
     *     path: string,
     *     method: string,
     *     uri: string,
     *     response_dto: array{class: string, path: string}|null,
     * }|null
     */
    public function requestFromSource(string $path, string $source): ?array
    {
        $class = $this->classes->classNameFromSource($source);
        $method = $this->requestMethod($source);
        $uri = $this->requestUri($source);

        if ($class === null || $method === null || $uri === null) {
            return null;
        }

        return [
            'class' => $class,
            'path' => $path,
            'method' => $method,
            'uri' => $uri,
            'response_dto' => $this->responseDto($source),
        ];
    }

    private function requestMethod(string $source): ?string
    {
        $match = [];

        if (! preg_match('/protected\s+Method\s+\$method\s*=\s*Method::([A-Z]+)/', $source, $match)) {
            return null;
        }

        return $match[1];
    }

    private function requestUri(string $source): ?string
    {
        $method = [];

        if (! preg_match('/function\s+resolveEndpoint\(\)\s*:\s*string\s*\{(.*?)\n\s*\}/s', $source, $method)) {
            return null;
        }

        $return = [];

        if (! preg_match('/return\s+(.*?);/s', $method[1], $return)) {
            return null;
        }

        return $this->normalizer->normalizePhpEndpointExpression($return[1]);
    }

    /**
     * @return array{class: string, path: string}|null
     */
    private function responseDto(string $source): ?array
    {
        $match = [];

        if (! preg_match(
            '/function\s+createDtoFromResponse\(.*?\)\s*:\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/s',
            $source,
            $match,
        )) {
            return null;
        }

        $class = $this->classes->resolveClassReference($match[1], $source);
        $path = $this->paths->sdkClassPath($class);

        if ($path === null) {
            return null;
        }

        return [
            'class' => $class,
            'path' => $path,
        ];
    }
}
