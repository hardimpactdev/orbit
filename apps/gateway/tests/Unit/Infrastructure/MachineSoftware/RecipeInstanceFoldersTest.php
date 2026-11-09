<?php

declare(strict_types=1);

use App\Infrastructure\MachineSoftware\RecipeLoader;

/** The shipped recipe with `$change` applied to its decoded document. */
function recipeWithInstances(Closure $change): string
{
    $document = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/resources/machine-software/recipe.json'), true, flags: JSON_THROW_ON_ERROR);
    $change($document);

    return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

it('reads the two shared folders, a tool part with the tool\'s own pin', function (): void {
    $recipe = new RecipeLoader()->decode(recipeWithInstances(static fn () => null));
    $node = $recipe->tools['node'];

    expect(array_keys($recipe->instances->runtime))->toBe(['node', 'bun', 'composer', 'ripgrep'])
        ->and(array_keys($recipe->instances->agent))->toBe(['t3', 'claude-code', 'codex'])
        ->and($recipe->instances->runtime['node']->artifact)->toBe($node->artifacts['amd64'])
        ->and($recipe->instances->runtime['node']->version)->toBe($node->version)
        ->and($recipe->instances->agent['claude-code']->artifact->algorithm)->toBe('sha512')
        ->and($recipe->instances->agent['codex']->bin)->toBe(['codex' => 'package/vendor/x86_64-unknown-linux-musl/bin/codex']);
});

it('keeps the shared folders out of the software identity, so a new T3 rebuilds no task image', function (): void {
    $before = new RecipeLoader()->decode(recipeWithInstances(static fn () => null));
    $after = new RecipeLoader()->decode(recipeWithInstances(static function (array &$recipe): void {
        $recipe['instances']['agent']['t3']['version'] = '0.1.8';
        $recipe['instances']['agent']['t3']['artifacts']['amd64'] = ['url' => 'https://github.com/pingdotgg/t3code/releases/download/v0.1.8/t3-0.1.8-linux-x64.tar.gz', 'sha256' => str_repeat('b', 64)];
    }));

    expect($after->identity)->toBe($before->identity)
        ->and($after->instances->agent['t3']->version)->toBe('0.1.8');
});

it('refuses a malformed folder part', function (Closure $change, string $message): void {
    expect(fn () => new RecipeLoader()->decode(recipeWithInstances($change)))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no instances' => [static function (array &$recipe): void {
        unset($recipe['instances']);
    }, 'expected exactly fields'],
    'a third folder' => [static function (array &$recipe): void {
        $recipe['instances']['extra'] = [];
    }, 'instances: expected exactly fields runtime, agent'],
    'a program twice' => [static function (array &$recipe): void {
        $recipe['instances']['agent']['codex']['bin']['claude'] = 'x';
    }, 'is not a new lower-case program name'],
    'a path out of the artifact' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['ripgrep']['bin']['rg'] = '../../etc/passwd';
    }, 'expected a relative path inside the artifact'],
    'no program' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['ripgrep']['bin'] = new stdClass;
    }, 'expected at least one program'],
    'an Ubuntu tool' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['node']['tool'] = 'composer';
    }, 'expected an artifact tool of the recipe'],
    'a channel URL' => [static function (array &$recipe): void {
        $recipe['instances']['agent']['t3']['artifacts']['amd64']['url'] = 'https://github.com/pingdotgg/t3code/releases/latest/download/t3-linux-x64.tar.gz';
    }, 'expected the exact version in an immutable release URL'],
    'a short digest' => [static function (array &$recipe): void {
        $recipe['instances']['agent']['t3']['artifacts']['amd64']['sha256'] = 'abc';
    }, 'expected 64 lowercase hexadecimal characters'],
    'an empty folder' => [static function (array &$recipe): void {
        $recipe['instances']['agent'] = new stdClass;
    }, 'instances.agent: expected at least one part'],
]);
