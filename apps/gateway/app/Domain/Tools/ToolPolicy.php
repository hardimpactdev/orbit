<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Shared\ResourceOperationException;
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
        if (! is_array($document) || array_diff(array_keys($document), ['schema', 'packages']) !== []
            || ($document['schema'] ?? null) !== 1 || ! is_array($document['packages'] ?? null) || ! array_is_list($document['packages'])) {
            $this->invalid();
        }
        $packages = [];
        $resolver = app(ToolDefinitionResolver::class);
        foreach ($document['packages'] as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['manager', 'package', 'version', 'constraint', 'repository']) !== []
                || ! is_string($entry['manager'] ?? null) || ! is_string($entry['package'] ?? null) || ! is_string($entry['version'] ?? null)
                || (isset($entry['constraint']) && ! is_string($entry['constraint'])) || (isset($entry['repository']) && ! is_array($entry['repository']))) {
                $this->invalid();
            }
            $manager = $this->managers->find($entry['manager']);
            $key = $entry['manager'].':'.$entry['package'];
            if ($manager === null || ! $manager->validatePackage($entry['package']) || isset($packages[$key])) {
                $this->invalid();
            }
            try {
                $repository = isset($entry['repository']) ? ValidatedData::object($entry['repository']) : null;
            } catch (InvalidArgumentException) {
                $this->invalid();
            }
            $definition = new ToolDefinition($entry['version'], $entry['constraint'] ?? null, $repository);
            $resolver->validate($manager, $definition);
            $packages[$key] = $definition;
        }

        return new ActiveToolPolicy('file', $path, $packages);
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('tool.catalog_invalid', 'The active Tool catalog is unreadable or invalid.', 422);
    }
}
