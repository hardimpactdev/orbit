<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;

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
    $processParameters = data_get($schema, 'paths./processes.get.parameters');

    Assert::assertIsArray($processParameters);

    $processParameterNames = [];

    foreach ($processParameters as $processParameter) {
        Assert::assertIsArray($processParameter);

        $processParameterNames[] = $processParameter['name'] ?? null;
    }

    Assert::assertSame(['node', 'app', 'workspace'], $processParameterNames);

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
