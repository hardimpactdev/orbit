<?php

declare(strict_types=1);

use App\Infrastructure\MachineSoftware\RecipeLoader;

/** The shipped recipe with one change to its instances.php section. */
function staticPhpRecipe(callable $change): string
{
    $recipe = json_decode((string) file_get_contents(resource_path('machine-software/recipe.json')), false, 64, JSON_THROW_ON_ERROR);
    $change($recipe->instances->php);

    return json_encode($recipe, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

it('pins the static build of PHP 8.5 with the Node extensions less those a static binary cannot load', function (): void {
    $recipe = new RecipeLoader()->source();
    $build = $recipe->instances->php['8.5'];

    expect($build->phpVersion)->toStartWith('8.5.')
        ->and($build->extensions)->toBe(array_values(array_diff($recipe->php('8.5')->extensions, ['ffi', 'pcov'])))
        ->and($build->extensions)->toContain('pdo_pgsql', 'redis', 'intl', 'zend opcache', 'opentelemetry')
        ->and($build->url)->toContain("/static-php-{$build->phpVersion}-".substr($build->build, 0, 12).'/');
});

it('reads a published build as an artifact pin, and one that is not published yet as none', function (): void {
    $digest = str_repeat('a', 64);
    $published = new RecipeLoader()->decode(staticPhpRecipe(function (object $section) use ($digest): void {
        $section->versions->{'8.5'}->sha256 = $digest;
    }))->instances->php['8.5'];
    $pending = new RecipeLoader()->decode(staticPhpRecipe(function (object $section): void {
        $section->versions->{'8.5'}->sha256 = null;
    }))->instances->php['8.5'];

    expect($published->artifact?->location)->toBe($published->url)
        ->and($published->artifact?->digest)->toBe($digest)
        ->and($published->artifact?->algorithm)->toBe('sha256')
        ->and($pending->artifact)->toBeNull();
});

it('keeps the static build out of the software identity of Nodes and images', function (): void {
    $shipped = new RecipeLoader()->source();
    $changed = new RecipeLoader()->decode(staticPhpRecipe(function (object $section): void {
        $section->versions->{'8.5'}->sha256 = str_repeat('b', 64);
    }));

    expect($changed->identity)->toBe($shipped->identity);
});

it('refuses a static build pin that does not hold together', function (callable $change, string $message): void {
    expect(fn () => new RecipeLoader()->decode(staticPhpRecipe($change)))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'an unknown PHP version' => [fn (object $section) => $section->versions->{'8.3'} = $section->versions->{'8.5'}, 'instances.php.versions.8.3: expected a PHP version of the recipe.'],
    'a release of another PHP version' => [fn (object $section) => $section->versions->{'8.5'}->php_version = '8.4.27', 'instances.php.versions.8.5.php_version: expected a PHP 8.5 release'],
    'a short build id' => [fn (object $section) => $section->versions->{'8.5'}->build = 'abc', 'instances.php.versions.8.5.build: expected the build id'],
    'a URL that is not HTTPS' => [fn (object $section) => $section->versions->{'8.5'}->url = 'http://example.test/php.tar.gz', 'instances.php.versions.8.5.url: expected an HTTPS release URL.'],
    'a malformed digest' => [fn (object $section) => $section->versions->{'8.5'}->sha256 = 'latest', 'instances.php.versions.8.5.sha256: expected the published SHA-256'],
    'an exclusion that is no extension' => [fn (object $section) => $section->without = ['ffi', 'xdebug'], 'instances.php.without: [xdebug] are not PHP 8.5 extensions.'],
    'a FrankenPHP channel' => [fn (object $section) => $section->frankenphp = 'latest', 'instances.php.frankenphp: expected an exact FrankenPHP release'],
    'a static-php-cli branch' => [fn (object $section) => $section->static_php_cli = 'v3', 'instances.php.static_php_cli: expected a full static-php-cli commit SHA.'],
    'an unknown key' => [fn (object $section) => $section->mirror = 'https://example.com', 'instances.php: expected exactly fields'],
]);
