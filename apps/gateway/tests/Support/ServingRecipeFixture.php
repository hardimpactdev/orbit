<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

final class ServingRecipeFixture
{
    /** Write the serving catalogs with no automatic Conn Server canary. */
    public static function write(string $gateway): void
    {
        $directory = $gateway.'/resources/machine-software';
        File::ensureDirectoryExists($directory);
        $catalog = Yaml::parseFile(resource_path('machine-software/conn-server-releases.yaml'));
        foreach ($catalog['releases'] as &$release) {
            unset($release['release_canary']);
        }
        File::put($directory.'/conn-server-releases.yaml', Yaml::dump($catalog, 6));
        File::copy(resource_path('machine-software/core.yaml'), $directory.'/core.yaml');
    }
}
