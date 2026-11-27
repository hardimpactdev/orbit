<?php

declare(strict_types=1);

use App\Infrastructure\MachineSoftware\RecipeLoader;

/** The shipped recipe with one tool pin, and its URL, at another version. */
function recipeWithToolVersion(string $name, string $version): string
{
    $recipe = json_decode((string) file_get_contents(resource_path('machine-software/core.yaml')), false, 64, JSON_THROW_ON_ERROR);
    $tool = $recipe->tools->{$name};
    $tool->artifacts->amd64->url = str_replace($tool->version, $version, $tool->artifacts->amd64->url);
    $tool->version = $version;

    return json_encode($recipe, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

it('takes a Sury pin built for the recipe Ubuntu release in either of Sury\'s version forms', function (string $version): void {
    expect(new RecipeLoader()->decode(recipeWithToolVersion('libgd3', $version))->php('8.5')->packages)->toContain('libgd3');
})->with(['2.3.3-17+debsuryorg26.04.1+php+2', '2.3.3-14+0~20260731.21+ubuntu26.04~1.gbp938f32']);

it('refuses a Sury pin built for another Ubuntu release', function (string $version): void {
    expect(fn () => new RecipeLoader()->decode(recipeWithToolVersion('libgd3', $version)))
        ->toThrow(InvalidArgumentException::class, "php.versions.8.5: [libgd3] version [{$version}] is not built for Ubuntu 26.04.");
})->with(['2.3.3-17+debsuryorg24.04.1+php+2', '2.3.3-17+debsuryorg26.041+php+2', '2.3.3-14+0~20260731.21+ubuntu24.04~1.gbp938f32']);

it('builds PHP 8.4 from the Sury 8.4.28 security release', function (): void {
    $build = new RecipeLoader()->decode((string) file_get_contents(resource_path('machine-software/core.yaml')))->php('8.4');

    expect($build->phpVersion)->toBe('8.4.28')->and($build->packages)->toContain('php8.4-cli', 'php8.4-fpm', 'php8.4-common');
});
