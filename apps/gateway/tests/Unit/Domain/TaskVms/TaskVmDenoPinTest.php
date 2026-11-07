<?php

declare(strict_types=1);

use App\Infrastructure\MachineSoftware\RecipeLoader;

it('pins the Deno of a task VM to the version the repository CI sets up', function (): void {
    $recipe = new RecipeLoader()->load();
    $workflow = (string) file_get_contents(dirname(base_path(), 2).'/.github/workflows/swamp-shape.yml');
    preg_match_all('/deno-version: v?(\S+)/', $workflow, $versions);

    // The Project check of a task VM runs the same Deno tests and Swamp shape checks as CI, so both use one version.
    expect($versions[1])->not->toBeEmpty()
        ->and(array_unique($versions[1]))->toBe([$recipe->tools['deno']->version])
        ->and($recipe->tools['deno']->artifacts['amd64']->location)->toContain('/v'.$recipe->tools['deno']->version.'/');
});
