<?php

declare(strict_types=1);

namespace App\Librarian;

final readonly class CommandCatalogPhpClassInspector
{
    public function classNameFromSource(string $source): ?string
    {
        $namespace = [];
        $class = [];

        if (! preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)) {
            return null;
        }

        if (! preg_match('/\b(?:final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $source, $class)) {
            return null;
        }

        return "{$namespace[1]}\\{$class[1]}";
    }

    public function resolveClassReference(string $type, string $source): string
    {
        $type = ltrim(string: $type, characters: '\\');

        if (str_contains($type, '\\')) {
            return $type;
        }

        $uses = $this->useStatements($source);

        if (($uses[$type] ?? null) !== null) {
            return $uses[$type];
        }

        $namespace = [];

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)) {
            return "{$namespace[1]}\\{$type}";
        }

        return $type;
    }

    /**
     * @return array<string, string>
     */
    public function useStatements(string $source): array
    {
        $matches = [];
        preg_match_all('/^use\s+([^;]+);/m', $source, $matches, PREG_SET_ORDER);
        $uses = [];

        foreach ($matches as $match) {
            $class = trim($match[1]);

            if (str_starts_with($class, 'function ') || str_starts_with($class, 'const ')) {
                continue;
            }

            $parts = preg_split('/\s+as\s+/i', $class);

            if ($parts === false || $parts === []) {
                continue;
            }

            $fqcn = trim($parts[0]);
            $alias = count($parts) > 1
                ? trim($parts[1])
                : basename(str_replace(search: '\\', replace: '/', subject: $fqcn));

            $uses[$alias] = $fqcn;
        }

        return $uses;
    }
}
