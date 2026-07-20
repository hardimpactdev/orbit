<?php

declare(strict_types=1);

use App\Models\Project;
use App\Services\Apps\AppResponsePayload;
use App\Services\Apps\DependencyAudit\AppDependencyAuditAggregatePayload;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

uses(TestCase::class);

it('defaults missing legacy app runtime attributes to php in response payloads', function (): void {
    $app = new Project;
    $app->setRawAttributes([
        'name' => 'docs',
        'repository' => null,
        'php_version' => '8.5',
        'adopted' => false,
    ], true);
    $app->setRelation('dependencyAuditSummaries', new Collection);

    $payload = new AppResponsePayload(new AppDependencyAuditAggregatePayload)->forApp($app);

    expect($payload)
        ->not
        ->toHaveKeys(['node', 'url', 'path', 'root', 'domain', 'environment'])
        ->and($payload['runtime'])
        ->toBe('php')
        ->and($payload['runtime_config'])
        ->toBe(['proxy_transport' => 'http']);
});
