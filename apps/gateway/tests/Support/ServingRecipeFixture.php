<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\File;

final class ServingRecipeFixture
{
    /** Write a serving recipe with no automatic Conn Server canary. */
    public static function write(string $gateway): void
    {
        $recipe = json_decode(File::get(resource_path('machine-software/recipe.json')), flags: JSON_THROW_ON_ERROR);
        unset($recipe->instances->agent->{'conn-server'}->release_canary);

        $directory = $gateway.'/resources/machine-software';
        File::ensureDirectoryExists($directory);
        File::put($directory.'/recipe.json', json_encode($recipe, JSON_THROW_ON_ERROR));
    }
}
