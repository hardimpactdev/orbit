<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Instances\InstanceContainer;
use App\Models\Instance;

/**
 * The plain Vite dev server of a development Instance container (docs/reference/processes-and-schedules.md#presets): the Project's
 * own `node_modules/.bin/vite` on a fixed port in the container. Browsers reach it through the container's development ports at
 * `https://<Route domain>:5173`, so it needs no assigned Vite port and no `/__orbit/vite` path.
 */
final readonly class VitePreset
{
    public const string NAME = 'vite';

    public const int Port = 5173;

    /** The Instance's Route URL, which a Project's Vite config reads to write `public/hot`. */
    public const string AppUrl = 'VITE_APP_URL';

    /** @return list<string> */
    public static function command(Instance $instance): array
    {
        return self::argv(InstanceContainer::application($instance).'/node_modules/.bin/vite');
    }

    /** @return list<string> */
    public static function definitionCommand(): array
    {
        return self::argv('vite');
    }

    /** @return list<string> */
    private static function argv(string $executable): array
    {
        return [$executable, '--host=0.0.0.0', '--port='.self::Port, '--strictPort'];
    }
}
