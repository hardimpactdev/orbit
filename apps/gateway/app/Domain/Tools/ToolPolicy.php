<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Shared\ResourceOperationException;
use App\Http\Requests\Tools\AptRepositoryRules;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class ToolPolicy
{
    public function __construct(private ToolManagerRegistry $managers) {}

    public function active(): ActiveToolPolicy
    {
        $row = DB::table('tool_policy')->where('id', 1)->first();
        $path = is_string($row?->catalog_path) ? $row->catalog_path : null;

        return $row?->mode === 'file' ? $this->fromFile($path) : new ActiveToolPolicy('database', null);
    }

    public function fromFile(?string $path): ActiveToolPolicy
    {
        $file = $path ?? resource_path('machine-software/tools.yaml');
        if (! str_starts_with($file, '/') || ! is_file($file) || ! is_readable($file)) {
            $this->invalid();
        }
        $source = @file_get_contents($file, length: 1_048_577);
        if (! is_string($source) || strlen($source) > 1_048_576) {
            $this->invalid();
        }
        try {
            $document = Yaml::parse($source, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException) {
            $this->invalid();
        }
        if (! is_array($document) || array_diff(array_keys($document), ['schema', 'repositories', 'packages']) !== []
            || ($document['schema'] ?? null) !== 1 || ! is_array($document['packages'] ?? null) || ! array_is_list($document['packages'])
            || (isset($document['repositories']) && (! is_array($document['repositories']) || ! array_is_list($document['repositories'])))) {
            $this->invalid();
        }
        // The catalog's apt repositories, by name: each is published on a Node whose enrolled Tools name it.
        $repositories = [];
        foreach ($document['repositories'] ?? [] as $entry) {
            try {
                $definition = ValidatedData::object($entry);
                new AptRepositoryRules()->validate($definition, ToolManagerName::Apt->value);
            } catch (InvalidArgumentException|ResourceOperationException) {
                $this->invalid();
            }
            $name = ValidatedData::string($definition['name']);
            if (isset($repositories[$name])) {
                $this->invalid();
            }
            $repositories[$name] = $definition;
        }
        try {
            AptRepositories::assertDistinctAmong(array_values($repositories));
        } catch (ResourceOperationException) {
            $this->invalid();
        }
        $packages = [];
        $resolver = app(ToolDefinitionResolver::class);
        foreach ($document['packages'] as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['manager', 'package', 'version', 'constraint', 'repository']) !== []
                || ! is_string($entry['manager'] ?? null) || ! is_string($entry['package'] ?? null) || ! is_string($entry['version'] ?? null)
                || (isset($entry['constraint']) && ! is_string($entry['constraint']))
                || (isset($entry['repository']) && (! is_string($entry['repository']) || ! isset($repositories[$entry['repository']])))) {
                $this->invalid();
            }
            $manager = $this->managers->find($entry['manager']);
            $key = $entry['manager'].':'.$entry['package'];
            if ($manager === null || ! $manager->validatePackage($entry['package']) || isset($packages[$key])) {
                $this->invalid();
            }
            $definition = new ToolDefinition($entry['version'], $entry['constraint'] ?? null, $entry['repository'] ?? null);
            $resolver->validate($manager, $definition);
            $packages[$key] = $definition;
        }

        return new ActiveToolPolicy('file', $path, $packages, $repositories);
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('tool.catalog_invalid', 'The active Tool catalog is unreadable or invalid.', 422);
    }
}
