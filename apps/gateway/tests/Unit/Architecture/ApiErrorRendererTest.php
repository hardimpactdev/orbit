<?php

declare(strict_types=1);

use App\Http\Responses\RendersApiError;

/**
 * Every exception class that declares render() in the Gateway or an extension.
 *
 * @return list<class-string<Throwable>>
 */
function exceptions_declaring_render(): array
{
    $project = dirname(__DIR__, 3);
    $composer = json_decode((string) file_get_contents($project.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $exceptions = [];

    foreach ($composer['autoload']['psr-4'] as $namespace => $directory) {
        if (! str_starts_with($namespace, 'App\\') && ! str_starts_with($namespace, 'Orbit\\Extensions\\')) {
            continue;
        }

        $root = $project.'/'.rtrim($directory, '/');

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || ! str_contains((string) file_get_contents($file->getPathname()), 'function render(')) {
                continue;
            }

            $class = $namespace.str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));

            if (class_exists($class) && is_subclass_of($class, Throwable::class)) {
                $exceptions[] = $class;
            }
        }
    }

    sort($exceptions);

    return $exceptions;
}

it('makes every exception that renders itself a RendersApiError, so its status and activity code follow', function (): void {
    $exceptions = exceptions_declaring_render();

    expect($exceptions)->not->toBeEmpty();

    foreach ($exceptions as $exception) {
        expect(is_subclass_of($exception, RendersApiError::class))->toBeTrue("{$exception} declares render() but does not implement RendersApiError.");
    }
});
