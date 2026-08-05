<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;

final class ProcessRuntimeApp
{
    public static function make(App $app, Node $node, ?Instance $instance = null): App
    {
        if (! $instance instanceof Instance) {
            $app->node_id = $node->id;

            return $app;
        }

        $runtimeApp = clone $app;
        $runtimeApp->node_id = $node->id;
        $config = $instance->driver_config;

        if (! $config instanceof OrbitInstanceDriverConfigData) {
            return $runtimeApp;
        }

        $runtimeApp->forceFill(array_filter([
            'path' => self::filledInstanceValue($config->path),
            'document_root' => self::filledInstanceValue($config->document_root),
            'domain' => self::filledInstanceValue($config->domain),
        ]));

        return $runtimeApp;
    }

    private static function filledInstanceValue(?string $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
