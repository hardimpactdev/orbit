<?php

declare(strict_types=1);

namespace App\Librarian;

final class CommandCatalogSdkRequestIndex
{
    /** @var array<string, array{class: string, path: string, response_dto: array{class: string, path: string}|null}>|null */
    private ?array $requestsByEndpoint = null;

    public function __construct(
        private readonly CommandCatalogRepositoryPaths $paths,
        private readonly CommandCatalogSdkRequestParser $parser,
        private readonly CommandCatalogEndpointNormalizer $normalizer,
    ) {}

    /**
     * @return array{class: string, path: string, response_dto: array{class: string, path: string}|null}|null
     */
    public function requestForEndpoint(string $method, string $uri): ?array
    {
        return $this->requestsByEndpoint()[$this->normalizer->endpointKey($method, $uri)] ?? null;
    }

    /**
     * @return array<string, array{class: string, path: string, response_dto: array{class: string, path: string}|null}>
     */
    private function requestsByEndpoint(): array
    {
        if ($this->requestsByEndpoint !== null) {
            return $this->requestsByEndpoint;
        }

        $requests = [];

        foreach ($this->paths->phpFiles('packages/sdk/src/Requests') as $path) {
            $source = file_get_contents($this->paths->absolutePath($path));

            if ($source === false) {
                continue;
            }

            $request = $this->parser->requestFromSource($path, $source);

            if ($request === null) {
                continue;
            }

            $requests[$this->normalizer->endpointKey($request['method'], $request['uri'])] = [
                'class' => $request['class'],
                'path' => $request['path'],
                'response_dto' => $request['response_dto'],
            ];
        }

        ksort($requests);

        return $this->requestsByEndpoint = $requests;
    }
}
