<?php

declare(strict_types=1);

/**
 * The extension namespaces, from the Gateway's PSR-4 map. Pest matches a namespace only at a PSR-4 root or below it,
 * so each extension is listed rather than `Orbit\Extensions` as a whole.
 *
 * @return array<string, string> namespace => directory
 */
function extension_namespaces(): array
{
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $map = is_array($composer) && is_array($composer['autoload']['psr-4'] ?? null) ? $composer['autoload']['psr-4'] : [];
    $namespaces = [];
    foreach ($map as $namespace => $directory) {
        if (is_string($namespace) && is_string($directory) && str_starts_with($namespace, 'Orbit\\Extensions\\')) {
            $namespaces[rtrim($namespace, '\\')] = rtrim($directory, '/');
        }
    }

    return $namespaces;
}

// Core reaches an extension only through a plug-in interface (docs/reference/extensions.md#the-boundary).
arch('core never uses extension code')
    ->expect('App')
    ->not->toUse(array_keys(extension_namespaces()));

it('maps every extension directory to its own namespace, so the boundary covers it', function (): void {
    $directories = glob(dirname(__DIR__, 3).'/extensions/*', GLOB_ONLYDIR) ?: [];
    $mapped = array_map(static fn (string $directory): string => explode('/', $directory)[1] ?? '', extension_namespaces());

    expect(array_map(basename(...), $directories))->toEqualCanonicalizing(array_values($mapped))
        ->and(array_values(extension_namespaces()))->each->toEndWith('/src');
});
