<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Projects\ProjectEnvironmentTemplateRefresh;
use App\Models\Instance;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/ContainerInstanceHelpers.php';

function nativeDotenvInstance(string $example): Instance
{
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    app(ProjectEnvironmentTemplateRefresh::class)->recordRead($project, str_repeat('a', 40), 'fixture', $example);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'native',
        'checkout_path' => '/workspace/native', 'root' => '.', 'status' => 'active', 'provisioning_step' => 'active',
        'source_is_laravel' => false, 'first_setup_pending' => false]);
    app(DevelopmentCreateEnvironment::class)->admit($instance, $project);

    return $instance;
}

it('captures any whole-value live reference without changing ordinary entry shape', function (): void {
    $instance = nativeDotenvInstance(<<<'ENV'
PLAIN=value
TOKEN=synthetic-token
VITE_NON_LISTED="${TOKEN}"
VITE_FORWARD=${Z_LATER}
Z_LATER=later
MIXED="${TOKEN}/x"
SINGLE='${TOKEN}'
ESCAPED="\${TOKEN}"
ENV);
    $entries = $instance->project->environmentTemplate()->sole()->entries;
    expect($entries[0])->toBe(['key' => 'PLAIN', 'value' => 'value', 'example' => 'value', 'removed' => false]);
    expect($entries[2])->toBe(['key' => 'VITE_NON_LISTED', 'value' => '${TOKEN}', 'example' => '${TOKEN}', 'removed' => false, 'reference' => 'TOKEN']);
    expect($entries[3]['reference'])->toBe('Z_LATER');
    foreach (array_slice($entries, 5) as $entry) {
        expect($entry)->not->toHaveKey('reference');
    }

    $contents = app(DevelopmentCreateEnvironment::class)->content($instance);
    expect($contents)->toContain('VITE_NON_LISTED="${TOKEN}"', 'VITE_FORWARD="${Z_LATER}"', 'MIXED="\${TOKEN}/x"', 'SINGLE="\${TOKEN}"', 'ESCAPED="\${TOKEN}"');
    expect(Dotenv::parse($contents))->toMatchArray(['VITE_NON_LISTED' => 'synthetic-token', 'VITE_FORWARD' => '${Z_LATER}']);
    expect(app(InstanceEnvironmentStore::class)->values($instance)['VITE_NON_LISTED'])->toBe('${TOKEN}');
});

it('does not change production rendering even when given captured references', function (): void {
    $example = file_get_contents(__DIR__.'/../../../Fixtures/instance-environment-awkward.env.example');
    $instance = nativeDotenvInstance($example);
    $values = app(InstanceEnvironmentStore::class)->values($instance);
    $references = [];
    foreach ($instance->project->environmentTemplate()->sole()->entries as $entry) {
        if (isset($entry['reference'])) {
            $references[$entry['key']] = $entry['reference'];
        }
    }
    $context = new InstanceEnvironmentContext(instanceId: $instance->id, projectId: $instance->project_id,
        nodeId: $instance->node_id, environment: 'production', path: '/srv/production/.env', executionUser: 'orbit',
        laravel: false, routeId: null, routeDomain: null, nodeStatus: 'active', node: $instance->node);
    // Captured from main before #3405; production's writer and callers have not changed.
    $expected = file_get_contents(__DIR__.'/../../../Fixtures/instance-environment-awkward.rendered');

    expect(app(InstanceEnvironmentRenderer::class)->render($context, $values))->toBe($expected);
    expect(app(InstanceEnvironmentRenderer::class)->render($context, $values, $references))->toBe($expected);
});

it('keeps release 1917 stored values and ordinary rendering unchanged', function (): void {
    $instance = nativeDotenvInstance('APP_NAME=Laravel'."\n".'VITE_APP_NAME="${APP_NAME}"');
    app(InstanceEnvironmentStore::class)->seedForCreate($instance, ['VITE_APP_NAME' => 'Laravel']);
    $stored = app(InstanceEnvironmentStore::class)->values($instance);

    expect(app(DevelopmentCreateEnvironment::class)->content($instance))->toContain('VITE_APP_NAME="Laravel"');
    expect(app(InstanceEnvironmentStore::class)->values($instance))->toBe($stored);
});

it('keeps awkward mixed and literal values byte-identical while enabling only whole-value references', function (): void {
    $instance = nativeDotenvInstance(file_get_contents(__DIR__.'/../../../Fixtures/instance-environment-awkward.env.example'));
    $expected = file_get_contents(__DIR__.'/../../../Fixtures/instance-environment-awkward.rendered');
    $expected = str_replace(
        ['COPY="\${SOURCE}"', 'UNDEFINED="\${MISSING}"', 'VITE_AWS="\${AWS_SECRET_ACCESS_KEY}"', 'VITE_PASSWORD="\${DB_PASSWORD}"'],
        ['COPY="${SOURCE}"', 'UNDEFINED="${MISSING}"', 'VITE_AWS="${AWS_SECRET_ACCESS_KEY}"', 'VITE_PASSWORD="${DB_PASSWORD}"'],
        $expected,
    );

    expect(app(DevelopmentCreateEnvironment::class)->content($instance))->toBe($expected);
});
