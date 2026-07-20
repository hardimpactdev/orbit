<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\Project;
use App\Services\Apps\DependencyAudit\AppDependencyAuditAggregatePayload;

final readonly class AppResponsePayload
{
    public function __construct(
        private AppDependencyAuditAggregatePayload $dependencyAuditPayload,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forApp(Project $app): array
    {
        $app->loadMissing('dependencyAuditSummaries');
        $runtime = $app->runtimeKind();

        return [
            'name' => $app->name,
            'repository' => $app->repository,
            'runtime' => $runtime->value,
            'runtime_config' => $runtime === AppRuntimeKind::Php ? $app->runtimeConfig()->toArray() : null,
            'php_version' => $app->php_version,
            'adopted' => $app->adopted,
            ...$this->dependencyAuditPayload->forApp($app),
        ];
    }
}
