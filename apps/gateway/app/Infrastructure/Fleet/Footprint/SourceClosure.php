<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The Gateway source files that a set of classes renders from: every class in the start directories, and
 * every core or extension class they import or name in their own namespace, followed transitively. Models, actions,
 * controllers, commands, and providers are data or callers, not rendering code, so the walk stops there.
 */
final class SourceClosure
{
    /** @var list<string> */
    public const array StopNamespaces = ['App\\Models\\', 'App\\Actions\\', 'App\\Http\\', 'App\\Console\\', 'App\\Providers\\'];

    /** @var array<string, list<string>> */
    private static array $cache = [];

    /** @var array<string, string>|null */
    private static ?array $roots = null;

    /**
     * @param  list<string>  $directories  Directories relative to the Gateway's base path, such as `app/Infrastructure/Caddy`.
     * @param  list<class-string>  $classes  More start classes under the core or extension PSR-4 roots.
     * @return list<string> Paths relative to the base path, sorted.
     */
    public static function of(array $directories, array $classes = []): array
    {
        $key = implode("\0", [...$directories, ...$classes]);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        foreach ($classes as $class) {
            if (self::path($class) === null || ! is_file(base_path(self::path($class)))) {
                throw new LogicException("The source closure reads only core and extension classes, not [{$class}].");
            }
        }

        $pending = $classes;

        foreach ($directories as $directory) {
            $absolute = base_path($directory);

            if (! is_dir($absolute)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $pending[] = self::className($directory.substr($file->getPathname(), strlen($absolute)));
                }
            }
        }

        $files = [];
        $seen = [];

        while ($pending !== []) {
            $class = array_pop($pending);

            if ($class === null || isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;
            $path = self::path($class);
            $source = $path === null ? false : @file_get_contents(base_path($path));

            if (! is_string($source)) {
                continue;
            }

            $files[] = $path;

            foreach (self::references($source) as $reference) {
                if (! isset($seen[$reference])) {
                    $pending[] = $reference;
                }
            }
        }

        sort($files, SORT_STRING);

        return self::$cache[$key] = array_values(array_unique($files));
    }

    /** @return list<string> */
    private static function references(string $source): array
    {
        $namespace = preg_match('/^namespace ([^;]+);/m', $source, $match) === 1 ? $match[1] : 'App';
        preg_match_all('/^use ((?:App|Orbit\\\\Extensions)\\\\[A-Za-z0-9_\\\\]+)(?: as \w+)?;/m', $source, $imports);
        preg_match_all('/\b([A-Z][A-Za-z0-9_]+)\b/', $source, $words);
        $references = $imports[1];

        foreach (array_unique($words[1]) as $word) {
            $candidate = $namespace.'\\'.$word;

            $path = self::path($candidate);
            if ($path !== null && is_file(base_path($path))) {
                $references[] = $candidate;
            }
        }

        return array_values(array_filter(
            array_unique($references),
            static fn (string $class): bool => self::path($class) !== null
                && ! array_any(self::StopNamespaces, static fn (string $stop): bool => str_starts_with($class, $stop))
                && preg_match('/^Orbit\\\\Extensions\\\\[^\\\\]+\\\\(?:Models|Actions|Http|Console)\\\\/', $class) !== 1
                && ! str_ends_with($class, 'ServiceProvider'),
        ));
    }

    /** @return array<string, string> */
    private static function roots(): array
    {
        if (self::$roots !== null) {
            return self::$roots;
        }
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
        $autoload = is_array($composer) && is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
        $map = is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];
        $roots = [];
        foreach ($map as $namespace => $directory) {
            if (is_string($namespace) && is_string($directory)
                && ($namespace === 'App\\' || str_starts_with($namespace, 'Orbit\\Extensions\\'))) {
                $roots[$namespace] = rtrim($directory, '/').'/';
            }
        }

        return self::$roots = $roots;
    }

    private static function path(string $class): ?string
    {
        foreach (self::roots() as $namespace => $directory) {
            if (str_starts_with($class, $namespace)) {
                return $directory.str_replace('\\', '/', substr($class, strlen($namespace))).'.php';
            }
        }

        return null;
    }

    private static function className(string $path): ?string
    {
        foreach (self::roots() as $namespace => $directory) {
            if (str_starts_with($path, $directory) && str_ends_with($path, '.php')) {
                return $namespace.str_replace('/', '\\', substr($path, strlen($directory), -4));
            }
        }

        return null;
    }
}
