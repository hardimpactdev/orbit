<?php

declare(strict_types=1);

namespace App\Librarian;

final readonly class CommandCatalogCliEndpointExtractor
{
    public function __construct(
        private CommandCatalogEndpointNormalizer $normalizer,
    ) {}

    /**
     * @return list<array{method: string, uri: string}>
     */
    public function endpoints(string $source): array
    {
        $staticEndpoints = $this->staticGatewayCallEndpoints($source);
        $concatenatedEndpoints = $this->concatenatedGatewayCallEndpoints($source);
        $parsedGatewayCallCount = count($staticEndpoints) + count($concatenatedEndpoints);

        return [
            ...$this->uniqueEndpoints([...$staticEndpoints, ...$concatenatedEndpoints]),
            ...$this->unparsedGatewayCallMarkers($source, $parsedGatewayCallCount),
            ...$this->streamToolActionEndpoints($source),
            ...$this->streamToolBulkActionEndpoints($source),
            ...$this->streamProgressEndpoints($source),
        ];
    }

    /**
     * @param  list<array{method: string, uri: string}>  $endpoints
     * @return list<array{method: string, uri: string}>
     */
    private function uniqueEndpoints(array $endpoints): array
    {
        $unique = [];

        foreach ($endpoints as $endpoint) {
            $key = $this->normalizer->endpointKey($endpoint['method'], $endpoint['uri']);
            $unique[$key] = $endpoint;
        }

        return array_values($unique);
    }

    /**
     * @return list<array{method: string, uri: string}>
     */
    private function staticGatewayCallEndpoints(string $source): array
    {
        $matches = [];
        preg_match_all(
            '/->gateway(Get|PostWithIdleTicks|Post|Put|Patch|Delete)\(\s*([\'"])(\/api\/[^\'"]+)\2(?!\s*\.\s*\$)\s*(?:,|\))/s',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(
            fn (array $match): array => [
                'method' => $match[1] === 'PostWithIdleTicks' ? 'POST' : strtoupper($match[1]),
                'uri' => $this->normalizer->normalizeApiUri($match[3]),
            ],
            $matches,
        );
    }

    /**
     * @return list<array{method: string, uri: string}>
     */
    private function concatenatedGatewayCallEndpoints(string $source): array
    {
        $matches = [];
        preg_match_all(
            '/->gateway(Get|PostWithIdleTicks|Post|Put|Patch|Delete)\(\s*([\'"])(\/api\/[^\'"]+)\2\s*\.\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*(?:,|\))/s',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $endpoints = [];

        foreach ($matches as $match) {
            $endpoints[] = [
                'method' => $match[1] === 'PostWithIdleTicks' ? 'POST' : strtoupper($match[1]),
                'uri' => $this->normalizer->normalizeApiUri($match[3].'{'.$match[4].'}'),
            ];
        }

        return $endpoints;
    }

    /**
     * @return list<array{method: string, uri: string}>
     */
    private function unparsedGatewayCallMarkers(string $source, int $parsedGatewayCallCount): array
    {
        $matches = [];
        preg_match_all('/->gateway(?:Get|PostWithIdleTicks|Post|Put|Patch|Delete)\(/', $source, $matches);
        $unparsedCount = count($matches[0] ?? []) - $parsedGatewayCallCount;

        if ($unparsedCount <= 0) {
            return [];
        }

        return [[
            'method' => 'UNPARSED',
            'uri' => '/__orbit_unparsed_gateway_call__',
        ]];
    }

    /**
     * @return list<array{method: string, uri: string}>
     */
    private function streamToolActionEndpoints(string $source): array
    {
        return $this->staticPostEndpoints(
            source: $source,
            pattern: '/->streamToolAction\([^,]+,\s*([\'"])([a-z0-9-]+)\1/s',
            uriPrefix: '/api/tools/{tool}/',
        );
    }

    /**
     * @return list<array{method: string, uri: string}>
     */
    private function streamToolBulkActionEndpoints(string $source): array
    {
        return $this->staticPostEndpoints(
            source: $source,
            pattern: '/->streamToolBulkAction\(\s*([\'"])([a-z0-9-]+)\1/s',
            uriPrefix: '/api/tools/',
        );
    }

    /**
     * @return list<array{method: string, uri: string}>
     */
    private function streamProgressEndpoints(string $source): array
    {
        $matches = [];
        preg_match_all('/->streamProgress\(\s*([\'"])(\/api\/[^\'"]+)\1\s*,/s', $source, $matches, PREG_SET_ORDER);

        return array_map(
            fn (array $match): array => [
                'method' => 'POST',
                'uri' => $this->normalizer->normalizeApiUri($match[2]),
            ],
            $matches,
        );
    }

    /**
     * @return list<array{method: string, uri: string}>
     */
    private function staticPostEndpoints(string $source, string $pattern, string $uriPrefix): array
    {
        $matches = [];
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $match): array => [
                'method' => 'POST',
                'uri' => $uriPrefix.$match[2],
            ],
            $matches,
        );
    }
}
