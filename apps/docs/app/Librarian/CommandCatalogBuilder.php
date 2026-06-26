<?php

declare(strict_types=1);

namespace App\Librarian;

final readonly class CommandCatalogBuilder
{
    public function __construct(
        private CliSurface $surface,
        private OrbitCommandDocs $docs,
        private CommandDocsRegistry $registry,
        private CommandCatalogDocsIndex $docsIndex,
        private CommandCatalogP4MappingIndex $p4MappingIndex,
    ) {}

    /**
     * @return array{
     *     schema_version: int,
     *     generated_from: array<string, string>,
     *     commands: array<string, array<string, mixed>>,
     *     registries: array<string, mixed>,
     * }
     */
    public function build(): array
    {
        $directories = $this->docsIndex->commandDirectoriesBySlug();
        $commands = [];

        foreach ($this->surface->publicCommands() as $command) {
            $commands[$command->name] = $this->commandEntry($command, $directories[$command->slug()] ?? null);
        }

        ksort($commands);

        return [
            'schema_version' => 1,
            'generated_from' => [
                'cli_surface' => 'apps/cli/orbit list --format=json',
                'docs_registry' => 'apps/docs/config/librarian-command-docs',
            ],
            'commands' => $commands,
            'registries' => [
                'error_codes' => $this->registry->errorCodes(),
                'warning_codes' => $this->registry->warningCodes(),
                'entity_schemas' => $this->registry->entitySchemas(),
                'shared_options' => $this->registry->sharedOptions(),
                'state_families' => $this->registry->stateFamilies(),
            ],
        ];
    }

    public function artifactPath(): string
    {
        return "{$this->docs->docsRoot()}/generated/command-catalog.json";
    }

    /**
     * @return array<string, mixed>|null
     */
    public function command(string $name): ?array
    {
        $catalog = $this->build();

        return $catalog['commands'][$name] ?? null;
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    public function toJson(array $catalog): string
    {
        return json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    public function write(): string
    {
        $path = $this->artifactPath();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, permissions: 0o775, recursive: true);
        }

        file_put_contents($path, $this->toJson($this->build()));

        return $path;
    }

    public function isFresh(): bool
    {
        $path = $this->artifactPath();

        return is_file($path) && file_get_contents($path) === $this->toJson($this->build());
    }

    /**
     * @return array<string, mixed>
     */
    private function commandEntry(CliCommand $command, ?string $directory): array
    {
        $docs = $this->docsIndex->docsEntry($command, $directory);

        return [
            'name' => $command->name,
            'slug' => $command->slug(),
            'family' => $command->familyToken(),
            'arguments' => $command->arguments,
            'options' => $command->options,
            'renderers' => [
                'json' => in_array('json', $command->options, strict: true),
                'stream_json' => in_array('stream-json', $command->options, strict: true),
            ],
            'destructive_consent' => null,
            'docs' => $docs,
            'public_options_documented' => $this->publicOptionsDocumented($command, $docs),
            'linked_test_files' => $directory === null ? [] : $this->docsIndex->linkedTestFiles($directory),
            'p4_mapping' => $this->p4MappingIndex->forCommand($command),
        ];
    }

    /**
     * @param  array<string, string|null>  $docs
     * @return array{json: bool, stream_json: bool}
     */
    private function publicOptionsDocumented(CliCommand $command, array $docs): array
    {
        $publicPath = $docs['public'] ?? null;

        if ($publicPath === null) {
            return [
                'json' => false,
                'stream_json' => false,
            ];
        }

        $contents = file_get_contents("{$this->docs->docsRoot()}/{$publicPath}");

        if ($contents === false) {
            return [
                'json' => false,
                'stream_json' => false,
            ];
        }

        return [
            'json' => in_array('json', $command->options, strict: true) && str_contains($contents, '--json'),
            'stream_json' =>
                in_array('stream-json', $command->options, strict: true) && str_contains($contents, '--stream-json'),
        ];
    }
}
