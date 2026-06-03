<?php

declare(strict_types=1);

namespace App\Enums\Processes;

use App\Models\App;

enum ProcessRuntime: string
{
    case Docker = 'docker';
    case Supervisor = 'supervisor';
    case Systemd = 'systemd';

    public static function defaultForApp(App $app): self
    {
        return $app->runtime_kind->usesPhpRuntimeContainer()
            ? self::Docker
            : self::Supervisor;
    }
}
