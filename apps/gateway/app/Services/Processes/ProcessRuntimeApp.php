<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;

final class ProcessRuntimeApp
{
    public static function make(Project $app, Node $node, ?AppInstance $instance = null): Project
    {
        if (! $instance instanceof AppInstance) {
            $app->node_id = $node->id;

            return $app;
        }

        $runtimeApp = clone $app;
        $runtimeApp->node_id = $node->id;
        $config = $instance->driver_config;

        if (! $config instanceof OrbitAppInstanceDriverConfigData) {
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
