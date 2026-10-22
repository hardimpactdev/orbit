<?php

declare(strict_types=1);
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * The extension namespaces and directories from the Gateway's PSR-4 map.
 *
 * @return array<string, string> namespace => directory
 */
function extension_namespaces(): array
{
    static $namespaces;

    if (is_array($namespaces)) {
        return $namespaces;
    }

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

function core_uses_extension_code(string $source): bool
{
    // Most core files do not mention Orbit at all. Imports and aliases are local to one PHP source.
    if (stripos($source, 'Orbit') === false) {
        return false;
    }

    $namespaces = array_keys(extension_namespaces());
    $extensionNames = implode('|', array_map(static fn (string $namespace): string => preg_quote(basename(str_replace('\\', '/', $namespace)), '/'), $namespaces));
    if (preg_match('/(?<![a-z0-9_\\\\])\\\\*Orbit\\\\+Extensions\\\\+(?:'.$extensionNames.')(?![a-z0-9_])/i', $source) === 1) {
        return true;
    }

    // Only imports of a root namespace need alias/group resolution. SDK imports cannot hide an extension reference.
    if (! array_any(token_get_all($source), static fn (mixed $token): bool => is_array($token)
        && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
        && in_array(strtolower(ltrim($token[1], '\\')), ['orbit', 'orbit\\extensions'], true))) {
        return false;
    }

    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $nodes = new NodeTraverser(new NameResolver)->traverse($parser->parse($source) ?? []);
    $finder = new NodeFinder;
    $names = $finder->findInstanceOf($nodes, Name::class);
    foreach ($finder->findInstanceOf($nodes, GroupUse::class) as $use) {
        foreach ($use->uses as $item) {
            $names[] = Name::concat($use->prefix, $item->name);
        }
    }

    return array_any($names, static fn (Name $name): bool => array_any(
        $namespaces,
        static fn (string $namespace): bool => strcasecmp($name->toString(), $namespace) === 0
            || str_starts_with(strtolower($name->toString()), strtolower($namespace).'\\'),
    ));
}

it('recognizes extension dependencies in each PHP reference shape', function (string $reference): void {
    expect(core_uses_extension_code('<?php namespace App; '.$reference))->toBeTrue();
})->with([
    'import' => 'use Orbit\\Extensions\\Vault\\Secret;',
    'fully qualified' => 'new \\Orbit\\Extensions\\Vault\\Secret;',
    'group import' => 'use Orbit\\Extensions\\Vault\\{Secret, Client};',
    'root group import' => 'use Orbit\\{Extensions\\Vault\\Secret};',
    'extension group import' => 'use Orbit\\Extensions\\{Vault\\Secret};',
    'case-insensitive root alias' => 'use orbit as Root; new Root\\Extensions\\Vault\\Secret;',
    'function import' => 'use function Orbit\\Extensions\\Vault\\secret;',
    'constant import' => 'use const Orbit\\Extensions\\Vault\\SECRET;',
    'root alias' => 'use Orbit as Root; new Root\\Extensions\\Vault\\Secret;',
    'namespace alias' => 'use Orbit\\Extensions as Features; new Features\\Vault\\Secret;',
    'escaped class string' => 'class_exists("Orbit\\\\Extensions\\\\Vault\\\\Secret");',
    'docblock' => '/** @var \\Orbit\\Extensions\\Vault\\Secret $secret */',
    'attribute' => '#[\\Orbit\\Extensions\\Vault\\Secret] class Example {}',
]);

it('allows unrelated namespaces without confusing them with extensions', function (string $reference): void {
    expect(core_uses_extension_code('<?php namespace App; '.$reference))->toBeFalse();
})->with([
    'SDK' => 'use Orbit\\Sdk\\Client;',
    'grouped SDK' => 'use Orbit\\{Sdk\\Client};',
    'generic extension root' => '$root = "Orbit\\\\Extensions\\\\";',
    'unused root alias' => 'use Orbit\\Extensions as Features;',
    'different root' => 'use Other\\Orbit\\Extensions\\Vault\\Secret;',
    'similar root' => 'use NotOrbit\\Extensions\\Vault\\Secret;',
    'no dependency' => 'class Example {}',
]);

// Core reaches an extension only through a plug-in interface (docs/reference/extensions.md#the-boundary).
it('core never uses extension code', function (): void {
    $files = Finder::create()->files()->in(dirname(__DIR__, 3).'/app')->name('*.php');
    $violations = [];
    $scanned = 0;

    foreach ($files as $file) {
        $scanned++;
        // Scan each source once, including imports, fully qualified references, docblocks, and escaped strings.
        if (core_uses_extension_code($file->getContents())) {
            $violations[] = $file->getRelativePathname();
        }
    }

    expect($scanned)->toBeGreaterThan(0)
        ->and($violations)->toBeEmpty(implode(PHP_EOL, $violations));
});

it('maps every extension directory to its own namespace, so the boundary covers it', function (): void {
    $directories = glob(dirname(__DIR__, 3).'/extensions/*', GLOB_ONLYDIR) ?: [];
    $mapped = array_map(static fn (string $directory): string => explode('/', $directory)[1] ?? '', extension_namespaces());

    expect(array_map(basename(...), $directories))->toEqualCanonicalizing(array_values($mapped))
        ->and(array_values(extension_namespaces()))->each->toEndWith('/src');
});
