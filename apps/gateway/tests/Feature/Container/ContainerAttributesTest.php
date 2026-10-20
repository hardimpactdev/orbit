<?php

declare(strict_types=1);

use Illuminate\Container\Attributes\Bind;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Attributes\Singleton;

/**
 * Every class in the Gateway's autoloaded sources whose file source `$matches`.
 *
 * @param  Closure(string): bool  $matches
 * @return list<class-string>
 */
function container_attribute_classes(Closure $matches): array
{
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $classes = [];

    foreach ($composer['autoload']['psr-4'] as $namespace => $directory) {
        if (! str_starts_with($namespace, 'App\\') && ! str_starts_with($namespace, 'Orbit\\')) {
            continue;
        }

        $root = base_path(rtrim($directory, '/'));
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if ($matches((string) file_get_contents($file->getPathname()))) {
                /** @var class-string $class */
                $class = $namespace.str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));
                $classes[] = $class;
            }
        }
    }

    sort($classes);

    return $classes;
}

/** @return list<class-string> */
function container_attribute_bound_types(): array
{
    return array_values(array_filter(
        container_attribute_classes(static fn (string $source): bool => str_contains($source, '#[Bind(')
            || str_contains($source, '#[Singleton]')
            || str_contains($source, '#[Scoped]')),
        static function (string $class): bool {
            $reflection = new ReflectionClass($class);

            return $reflection->getAttributes(Bind::class) !== []
                || $reflection->getAttributes(Singleton::class) !== []
                || $reflection->getAttributes(Scoped::class) !== [];
        },
    ));
}

it('resolves every attribute binding to its declared class and lifetime', function (): void {
    $app = $this->app;
    $types = container_attribute_bound_types();

    foreach ($types as $type) {
        $reflection = new ReflectionClass($type);
        // The Feature setup binds fakes for some of these types.
        $app->forgetInstance($type);
        $first = $app->make($type);

        foreach ($reflection->getAttributes(Bind::class) as $bind) {
            expect($first)->toBeInstanceOf($bind->newInstance()->concrete);
        }

        $singleton = $reflection->getAttributes(Singleton::class) !== [];
        if ($singleton || $reflection->getAttributes(Scoped::class) !== []) {
            $shared = $app->make($type) === $first;
            $app->forgetScopedInstances();

            expect([$type, $shared, $app->make($type) === $first])->toBe([$type, true, $singleton]);
        }
    }

    expect($types)->not->toBeEmpty();
});

// Laravel passes a parameter's default value unless its type is bound (Container::resolveClass). An attribute binds
// a type only when something first resolves it, so such a parameter would get its default in one process and the
// bound class in another. Those types stay bound in a service provider.
it('binds no type by attribute that a constructor gives a default', function (): void {
    $types = array_flip(container_attribute_bound_types());
    $defaulted = [];

    // Only classes whose constructor declares a default are loaded.
    $declaresDefault = static function (string $source): bool {
        $start = strpos($source, 'function __construct(');

        return $start !== false && str_contains(substr($source, $start, (int) strpos($source, '{', $start) - $start), ' = ');
    };

    foreach (container_attribute_classes($declaresDefault) as $class) {
        if (! class_exists($class)) {
            continue;
        }

        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $names = $type instanceof ReflectionNamedType ? [$type->getName()] : array_map(
                static fn (ReflectionType $part): string => $part instanceof ReflectionNamedType ? $part->getName() : '',
                $type instanceof ReflectionUnionType ? $type->getTypes() : [],
            );

            foreach ($names as $name) {
                if ($parameter->isDefaultValueAvailable() && isset($types[$name])) {
                    $defaulted[] = $class.'::$'.$parameter->getName().' ('.$name.')';
                }
            }
        }
    }

    expect($defaulted)->toBe([]);
});
