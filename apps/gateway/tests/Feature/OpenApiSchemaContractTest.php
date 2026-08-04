<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;

/**
 * @param  array<string, mixed>  $schema
 */
function assertProcessOpenApiContracts(array $schema): void
{
    $processParameters = data_get($schema, 'paths./processes.get.parameters');

    Assert::assertIsArray($processParameters);

    $processParameterNames = [];

    foreach ($processParameters as $processParameter) {
        Assert::assertIsArray($processParameter);

        $processParameterNames[] = $processParameter['name'] ?? null;
    }

    Assert::assertSame(['app', 'node', 'instance', 'workspace'], $processParameterNames);
    Assert::assertSame(
        ['running', 'stopped', 'crashed', 'unknown'],
        data_get(
            $schema,
            'paths./processes.get.responses.200.content.application/json.schema.properties.success.properties.data.properties.processes.items.properties.status.enum',
        ),
    );
    Assert::assertSame(
        ['app', 'node', 'instance', 'workspace', 'name'],
        array_keys(data_get(
            $schema,
            'paths./processes/start.post.requestBody.content.application/json.schema.properties',
        ) ?? []),
    );

    $startRuntimeProperties = data_get(
        $schema,
        'paths./processes/start.post.responses.200.content.application/json.schema.properties.success.properties.data.properties.runtimes.items.properties',
    ) ?? [];
    $restartRuntimeProperties = data_get(
        $schema,
        'paths./processes/restart.post.responses.200.content.application/json.schema.properties.success.properties.data.properties.runtimes.items.properties',
    ) ?? [];

    Assert::assertArrayHasKey('event', $startRuntimeProperties);
    Assert::assertArrayNotHasKey('events', $startRuntimeProperties);
    Assert::assertArrayHasKey('events', $restartRuntimeProperties);
    Assert::assertArrayNotHasKey('event', $restartRuntimeProperties);
    Assert::assertContains(
        'events',
        data_get(
            $schema,
            'paths./processes/restart.post.responses.200.content.application/json.schema.properties.success.properties.data.properties.runtimes.items.required',
        ) ?? [],
    );
    Assert::assertArrayNotHasKey(
        'options',
        data_get($schema, 'paths./processes', []) ?? [],
    );
}

test('gateway openapi export includes stable contract metadata', function (): void {
    ini_set('memory_limit', '1G');

    $path = storage_path('framework/testing/openapi/gateway-openapi.json');

    File::ensureDirectoryExists(dirname($path));
    File::delete($path);

    $exitCode = Artisan::call('scramble:export', [
        '--path' => $path,
    ]);

    Assert::assertSame(0, $exitCode);
    Assert::assertTrue(File::exists($path));

    /** @var array<string, mixed> $schema */
    $schema = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

    Assert::assertSame('Orbit Gateway API', data_get($schema, 'info.title'));
    Assert::assertSame(config('app.version'), data_get($schema, 'info.version'));
    Assert::assertIsString(data_get($schema, 'info.description'));
    Assert::assertNotSame('', data_get($schema, 'info.description'));
    Assert::assertSame('apiKey', data_get($schema, 'components.securitySchemes.orbitWireGuardIdentity.type'));
    Assert::assertSame('header', data_get($schema, 'components.securitySchemes.orbitWireGuardIdentity.in'));
    Assert::assertSame('X-Orbit-WireGuard-Ip', data_get(
        $schema,
        'components.securitySchemes.orbitWireGuardIdentity.name',
    ));
    Assert::assertIsArray(data_get($schema, 'components.schemas.OrbitSuccessEnvelope.properties.data'));
    Assert::assertSame('string', data_get(
        $schema,
        'components.schemas.OrbitErrorEnvelope.properties.error.properties.code.type',
    ));
    Assert::assertSame('toolStart', data_get($schema, 'paths./tools/{tool}/start.post.operationId'));
    Assert::assertSame('toolStop', data_get($schema, 'paths./tools/{tool}/stop.post.operationId'));
    Assert::assertSame('toolRestart', data_get($schema, 'paths./tools/{tool}/restart.post.operationId'));
    assertProcessOpenApiContracts($schema);

    /** @var array<string, mixed>|null $projectListItem */
    $projectListItem = data_get(
        target: $schema,
        key: 'paths./projects.get.responses.200.content.application/json.schema.properties.success.properties.data.properties.projects.items',
    );

    Assert::assertIsArray($projectListItem);
    Assert::assertSame([
        'name',
        'repository',
        'dependency_audit_status',
        'dependency_warning_count',
        'dependency_danger_count',
        'last_dependency_audit_at',
        'instance_count',
        'workspace_count',
    ], array_keys($projectListItem['properties'] ?? []));
    Assert::assertSame([
        'name',
        'repository',
        'dependency_audit_status',
        'dependency_warning_count',
        'dependency_danger_count',
        'last_dependency_audit_at',
        'instance_count',
        'workspace_count',
    ], $projectListItem['required'] ?? null);
    Assert::assertSame('string', data_get($projectListItem, 'properties.name.type'));
    Assert::assertSame(['string', 'null'], data_get($projectListItem, 'properties.repository.type'));
    Assert::assertSame('string', data_get($projectListItem, 'properties.dependency_audit_status.type'));
    Assert::assertSame('integer', data_get($projectListItem, 'properties.dependency_warning_count.type'));
    Assert::assertSame('integer', data_get($projectListItem, 'properties.dependency_danger_count.type'));
    Assert::assertSame(['string', 'null'], data_get($projectListItem, 'properties.last_dependency_audit_at.type'));
    Assert::assertSame('integer', data_get($projectListItem, 'properties.instance_count.type'));
    Assert::assertSame('integer', data_get($projectListItem, 'properties.workspace_count.type'));
    $projectListResponseStatuses = array_map(
        static fn (int|string $status): string => (string) $status,
        array_keys(data_get($schema, 'paths./projects.get.responses', [])),
    );
    sort($projectListResponseStatuses);

    Assert::assertSame(['200', '400', '403'], $projectListResponseStatuses);
    Assert::assertSame('array', data_get(
        $schema,
        'paths./projects.get.responses.200.content.application/json.schema.properties.success.properties.meta.type',
    ));

    /** @var array<string, mixed>|null $instanceSetupResponses */
    $instanceSetupResponses = data_get(target: $schema, key: 'paths./instances/{instance}/setup.post.responses');

    Assert::assertIsArray($instanceSetupResponses);
    Assert::assertSame(
        [],
        array_values(array_diff(
            ['200', '403', '404', '422'],
            array_keys($instanceSetupResponses),
        )),
    );

    /** @var array<string, mixed>|null $instanceSetupData */
    $instanceSetupData = data_get(
        target: $instanceSetupResponses,
        key: '200.content.application/json.schema.properties.success.properties.data',
    );

    Assert::assertIsArray($instanceSetupData);
    Assert::assertSame([
        'project',
        'instance',
        'node',
        'path',
        'url',
        'action',
        'setup_steps',
    ], array_keys($instanceSetupData['properties'] ?? []));
    Assert::assertSame('array', data_get(
        $instanceSetupResponses,
        '200.content.application/json.schema.properties.success.properties.meta.type',
    ));

    foreach (['403', '404', '422'] as $status) {
        Assert::assertIsArray(data_get(
            $instanceSetupResponses,
            "{$status}.content.application/json.schema.properties.error",
        ));
    }

    /** @var array<string, mixed>|null $updateAllItem */
    $updateAllItem = data_get(
        target: $schema,
        key: 'paths./update/all.post.responses.200.content.application/json.schema.properties.success.properties.data.properties.updates.items',
    );

    Assert::assertIsArray($updateAllItem);
    Assert::assertSame('array', data_get($updateAllItem, 'properties.roles.type'));
    Assert::assertSame('string', data_get($updateAllItem, 'properties.roles.items.type'));
    Assert::assertContains('roles', $updateAllItem['required'] ?? []);

    /** @var array<string, mixed>|null $activityListItem */
    $activityListItem = data_get(
        target: $schema,
        key: 'paths./activity.get.responses.200.content.application/json.schema.properties.success.properties.data.properties.activities.items',
    );
    /** @var array<string, mixed>|null $activityShowItem */
    $activityShowItem = data_get(
        target: $schema,
        key: 'paths./activity/{id}.get.responses.200.content.application/json.schema.properties.success.properties.data.properties.activity',
    );
    /** @var array<string, mixed>|null $relatedActivityItem */
    $relatedActivityItem = data_get(
        target: $schema,
        key: 'paths./activity/{id}.get.responses.200.content.application/json.schema.properties.success.properties.data.properties.related.items',
    );
    $activityFields = [
        'id',
        'occurred_at',
        'correlation_id',
        'type',
        'effect',
        'subject',
        'actor',
        'command',
        'description',
        'properties',
        'channel',
    ];

    foreach ([$activityListItem, $activityShowItem, $relatedActivityItem] as $activityItem) {
        Assert::assertIsArray($activityItem);
        Assert::assertSame($activityFields, array_keys($activityItem['properties'] ?? []));
        Assert::assertSame('string', data_get($activityItem, 'properties.effect.type'));
        Assert::assertSame('object', data_get($activityItem, 'properties.properties.type'));
        Assert::assertSame('string', data_get($activityItem, 'properties.subject.properties.name.type'));
        Assert::assertSame('string', data_get($activityItem, 'properties.actor.properties.node.type'));
        Assert::assertSame('string', data_get($activityItem, 'properties.channel.type'));
    }

    /** @var array<string, mixed>|null $scheduleListItem */
    $scheduleListItem = data_get(
        target: $schema,
        key: 'paths./schedules.get.responses.200.content.application/json.schema.properties.success.properties.data.properties.schedules.items',
    );

    Assert::assertIsArray($scheduleListItem);
    Assert::assertSame('object', $scheduleListItem['type'] ?? null);
    Assert::assertSame('string', data_get($scheduleListItem, 'properties.target.properties.name.type'));
    Assert::assertContains('target', $scheduleListItem['required'] ?? []);

    /** @var array<string, array<string, array<string, mixed>>> $paths */
    $paths = $schema['paths'];

    $operationIds = collect($paths)
        ->flatMap(
            fn (array $path): array => collect($path)
                ->only(['get', 'post', 'put', 'patch', 'delete'])
                ->pluck('operationId')
                ->filter()
                ->all(),
        )
        ->values();

    Assert::assertSame([], $operationIds->duplicates()->values()->all());
});
