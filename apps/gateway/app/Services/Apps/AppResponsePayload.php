<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Services\Apps\DependencyAudit\AppDependencyAuditAggregatePayload;

final readonly class AppResponsePayload
{
    public function __construct(
        private AppDependencyAuditAggregatePayload $dependencyAuditPayload,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forApp(App $app): array
    {
        $app->loadMissing(['node', 'dependencyAuditSummaries']);
        $runtime = $app->runtimeKind();

        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'runtime' => $runtime->value,
            'runtime_config' => $runtime === AppRuntimeKind::Php ? $app->runtimeConfig()->toArray() : null,
            'php_version' => $app->php_version,
            'worker_enabled' => $app->worker_enabled,
            'worker_config' => is_array($app->worker_config) ? $app->worker_config : null,
            'adopted' => $app->adopted,
            ...$this->dependencyAuditPayload->forApp($app),
        ];
    }
}
