<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceFolderPlan;
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

    $parts = array_keys($recipe->instances->runtime);

    expect(array_values(array_filter($parts, static fn (string $name): bool => ! str_starts_with($name, 'browser-'))))
        ->toBe(['node', 'bun', 'deno', 'composer', 'ripgrep', 'php-data-bridge', 'vite-plus'])
        ->and($recipe->instances->runtime['vite-plus']->artifact)->toBe($recipe->tools['vp-cli']->artifacts['amd64'])
        ->and($recipe->instances->runtime['vite-plus']->version)->toBe($recipe->tools['vp']->version)
        ->and($recipe->instances->runtime['vite-plus']->bin)->toBe(['vp' => 'vp'])
        ->and($recipe->instances->vitePlus?->part)->toBe('vite-plus')
        ->and($recipe->instances->vitePlus?->vp)->toBe($recipe->tools['vp'])
        ->and($recipe->instances->vitePlus?->node)->toBe($node->version)
        ->and(array_keys($recipe->instances->agent))->toBe(['t3', 'claude-code', 'codex'])
        ->and($recipe->instances->runtime['node']->artifact)->toBe($node->artifacts['amd64'])
        ->and($recipe->instances->runtime['node']->version)->toBe($node->version)
        ->and($recipe->instances->agent['claude-code']->artifact->algorithm)->toBe('sha512')
        ->and($recipe->instances->agent['codex']->bin)->toBe(['codex' => 'package/vendor/x86_64-unknown-linux-musl/bin/codex']);
});

it('pins the browser libraries and fonts as Debian packages of the recipe\'s Ubuntu snapshot, which give no program', function (): void {
    $recipe = new RecipeLoader()->decode(recipeWithInstances(static fn () => null));
    $browser = array_filter($recipe->instances->runtime, static fn (string $name): bool => str_starts_with($name, 'browser-'), ARRAY_FILTER_USE_KEY);
    $snapshot = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/resources/machine-software/recipe.json'), true, flags: JSON_THROW_ON_ERROR)['ubuntu']['snapshot'];

    // What a headless Chromium links and the Ubuntu image lacks, with their own dependencies; and one sans, serif, mono and emoji set.
    expect(array_keys($browser))->toContain('browser-libnss3', 'browser-libnspr4', 'browser-libgbm1', 'browser-libxkbcommon0',
        'browser-libasound2t64', 'browser-fonts-dejavu-core', 'browser-fonts-liberation', 'browser-fonts-noto-color-emoji')
        // The GPU drivers stay out: libgbm loads them only on demand, and a headless browser in a container has no GPU.
        ->not->toContain('browser-mesa-libgallium', 'browser-libllvm21');
    foreach ($browser as $name => $part) {
        expect($part->bin)->toBe([])
            ->and($part->artifact->algorithm)->toBe('sha256')
            ->and($part->artifact->location)->toStartWith("https://snapshot.ubuntu.com/ubuntu/{$snapshot}/pool/")
            ->and($part->artifact->location)->toEndWith('.deb');
    }
    expect(array_column(InstanceFolderPlan::for($recipe->instances)['runtime']['parts'], 'archive', 'name')['browser-libnss3'])->toBe('deb');
});

it('reads a part without `bin` as files that give the folder no program', function (): void {
    $bridge = new RecipeLoader()->decode(recipeWithInstances(static fn () => null))->instances->runtime['php-data-bridge'];

    expect($bridge->bin)->toBe([])
        ->and($bridge->version)->toBe('0.3.1')
        ->and($bridge->artifact->location)->toBe('https://github.com/nckrtl/php-data-bridge/releases/download/v0.3.1/php-data-bridge.tar.gz');
});

it('publishes Deno from the Node pin in the shared runtime and changes only that folder version', function (): void {
    $recipe = new RecipeLoader()->decode(recipeWithInstances(static fn () => null));
    $withoutDeno = new RecipeLoader()->decode(recipeWithInstances(static function (array &$recipe): void {
        unset($recipe['instances']['runtime']['deno']);
    }));

    $plan = InstanceFolderPlan::for($recipe->instances);
    $before = InstanceFolderPlan::for($withoutDeno->instances);
    $parts = array_column($plan['runtime']['parts'], null, 'name');
    $deno = $recipe->tools['deno'];

    expect($parts)->toHaveKey('deno');
    expect($parts['deno'])->toBe([
        'name' => 'deno', 'version' => $deno->version, 'url' => $deno->artifacts['amd64']->location,
        'archive' => 'zip', 'sha256' => $deno->artifacts['amd64']->digest, 'bin' => ['deno' => 'deno'],
    ]);
    expect($plan['runtime']['version'])->not->toBe($before['runtime']['version'])
        ->and($plan['agent'])->toBe($before['agent'])
        ->and($recipe->identity)->toBe($withoutDeno->identity);
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
    }, 'instances: expected exactly fields runtime, agent, php'],
    'a program twice' => [static function (array &$recipe): void {
        $recipe['instances']['agent']['codex']['bin']['claude'] = 'x';
    }, 'is not a new lower-case program name'],
    'a path out of the artifact' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['ripgrep']['bin']['rg'] = '../../etc/passwd';
    }, 'expected a relative path inside the artifact'],
    'no program' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['ripgrep']['bin'] = new stdClass;
    }, 'expected at least one program'],
    'a tool part without programs' => [static function (array &$recipe): void {
        unset($recipe['instances']['runtime']['node']['bin']);
    }, 'instances.runtime.node.bin: expected an object'],
    'a part without programs and with an unknown field' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['php-data-bridge']['extra'] = 'x';
    }, 'instances.runtime.php-data-bridge: expected exactly fields version, artifacts; got version, artifacts, extra.'],
    'an Ubuntu tool' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['node']['tool'] = 'composer';
    }, 'expected an artifact tool of the recipe'],
    'a channel URL' => [static function (array &$recipe): void {
        $recipe['instances']['agent']['t3']['artifacts']['amd64']['url'] = 'https://github.com/pingdotgg/t3code/releases/latest/download/t3-linux-x64.tar.gz';
    }, 'expected the exact version in an immutable release URL'],
    'a short digest' => [static function (array &$recipe): void {
        $recipe['instances']['agent']['t3']['artifacts']['amd64']['sha256'] = 'abc';
    }, 'expected 64 lowercase hexadecimal characters'],
    'Vite+ in the agent folder' => [static function (array &$recipe): void {
        $recipe['instances']['agent']['vite-plus'] = $recipe['instances']['runtime']['vite-plus'];
    }, "expected the runtime folder's one Vite+ part, with the one program vp"],
    'no Vite+' => [static function (array &$recipe): void {
        unset($recipe['instances']['runtime']['vite-plus']);
    }, 'instances.runtime: expected a Vite+ part, the recipe tool vp-cli.'],
    'Vite+ with a second program' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['vite-plus']['bin']['vpx'] = 'vp';
    }, "expected the runtime folder's one Vite+ part, with the one program vp"],
    'an empty folder' => [static function (array &$recipe): void {
        $recipe['instances']['agent'] = new stdClass;
    }, 'instances.agent: expected at least one part'],
]);
