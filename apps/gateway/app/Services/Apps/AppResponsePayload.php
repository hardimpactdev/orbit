<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;

final readonly class AppResponsePayload
{
    /**
     * @return array<string, mixed>
     */
    public function forApp(App $app): array
    {
        $app->loadMissing('node');

        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'runtime' => $app->runtime->value,
            'runtime_config' => $app->runtime === AppRuntimeKind::Php ? $app->runtimeConfig()->toArray() : null,
            'php_version' => $app->php_version,
            'worker_enabled' => $app->worker_enabled,
            'worker_config' => is_array($app->worker_config) ? $app->worker_config : null,
            'adopted' => $app->adopted,
        ];
    }
}
