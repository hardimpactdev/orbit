<?php

declare(strict_types=1);

namespace App\Librarian;

final class CommandCatalogCliEndpointIndex
{
    /** @var array<string, string>|null */
    private ?array $commandSourcesByName = null;

    public function __construct(
        private readonly CommandCatalogRepositoryPaths $paths,
        private readonly CommandCatalogCliEndpointExtractor $extractor,
        private readonly CommandCatalogEndpointNormalizer $normalizer,
    ) {}

    /**
     * @return array{method: string, uri: string}|null
     */
    public function primaryEndpointFor(CliCommand $command): ?array
    {
        $source = $this->commandSourcesByName()[$command->name] ?? null;

        if ($source === null) {
            return null;
        }

        $unique = [];

        foreach ($this->extractor->endpoints($source) as $endpoint) {
            $unique[$this->normalizer->endpointKey($endpoint['method'], $endpoint['uri'])] = $endpoint;
        }

        if (count($unique) !== 1) {
            $canonical = array_filter(
                $unique,
                static fn (array $endpoint): bool => ! str_ends_with((string) $endpoint['uri'], '/log-stream'),
            );

            return count($canonical) === 1 ? array_values($canonical)[0] : null;
        }

        return array_values($unique)[0];
    }

    /**
     * @return array<string, string>
     */
    private function commandSourcesByName(): array
    {
        if ($this->commandSourcesByName !== null) {
            return $this->commandSourcesByName;
        }

        $sources = [];

        foreach ($this->paths->phpFiles('apps/cli/app/Commands') as $path) {
            $source = file_get_contents($this->paths->absolutePath($path));

            if ($source === false) {
                continue;
            }

            $name = $this->commandNameFromSource($source);

            if ($name !== null) {
                $sources[$name] = $source;
            }
        }

        ksort($sources);

        return $this->commandSourcesByName = $sources;
    }

    private function commandNameFromSource(string $source): ?string
    {
        $match = [];

        if (! preg_match('/protected\s+\$signature\s*=\s*([\'"])(.*?)\1/s', $source, $match)) {
            return null;
        }

        $tokens = preg_split('/\s+/', trim($match[2]));

        if ($tokens === false || $tokens === []) {
            return null;
        }

        $name = $tokens[0];

        return $name !== '' ? $name : null;
    }
}
