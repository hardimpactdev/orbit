<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Services\Apps\AppResponsePayload;
use App\Services\Apps\DependencyAudit\AppDependencyAuditAggregatePayload;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

uses(TestCase::class);

it('defaults missing legacy app runtime attributes to php in response payloads', function (): void {
    $app = new App;
    $app->setRawAttributes([
        'name' => 'docs',
        'path' => '/srv/docs',
        'document_root' => 'public',
        'repository' => null,
        'php_version' => '8.5',
        'worker_enabled' => false,
        'worker_config' => null,
        'adopted' => false,
    ], true);
    $app->setRelation('node', new Node([
        'name' => 'beast',
        'tld' => 'test',
    ]));
    $app->setRelation('dependencyAuditSummaries', new Collection);

    $payload = new AppResponsePayload(new AppDependencyAuditAggregatePayload)->forApp($app);

    expect($payload['runtime'])->toBe('php')->and($payload['runtime_config'])->toBe(['proxy_transport' => 'http']);
});
