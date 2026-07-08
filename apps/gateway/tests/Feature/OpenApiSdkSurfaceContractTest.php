<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Orbit\Sdk\Laravel\GatewayRequest;
use PHPUnit\Framework\Assert;

test('gateway openapi schema-only operations are classified for sdk generation', function (): void {
    ini_set('memory_limit', '1G');

    $schema = exportOpenApiSchemaForSdkSurface();
    $surface = openApiSdkSurfaceContract();
    $sdkOperations = coveredSdkOperations();
    $surfaceOperations = openApiSdkSurfaceOperations($surface);

    $schemaOperations = collect(schemaOperations($schema));

    Assert::assertSame(159, $schemaOperations->count());

    $schemaOnlyOperations = $schemaOperations
        ->reject(fn (array $operation): bool => sdkCoversOperation($sdkOperations, $operation['operation']))
        ->values();

    Assert::assertSame(63, $schemaOnlyOperations->count());

    $classifiedOperations = collect($surfaceOperations)
        ->pluck('operation')
        ->values();

    Assert::assertSame(
        [],
        $schemaOnlyOperations
            ->pluck('operation')
            ->diff($classifiedOperations)
            ->values()
            ->all(),
    );

    Assert::assertSame(
        [],
        $classifiedOperations
            ->diff($schemaOnlyOperations->pluck('operation'))
            ->values()
            ->all(),
    );

    Assert::assertSame([], $classifiedOperations->duplicates()->values()->all());

    $classifications = [];

    foreach ($surfaceOperations as $operation) {
        $classifications[$operation['classification']] ??= 0;
        $classifications[$operation['classification']]++;
    }

    Assert::assertSame([
        'internal_only' => 8,
        'deferred_optional' => 33,
        'public_sdk' => 22,
    ], $classifications);

    $publicFollowUps = collect($surfaceOperations)
        ->where('classification', 'public_sdk')
        ->map(fn (array $operation): array => [
            'operation' => $operation['operation'],
            'follow_up' => $operation['follow_up'] ?? null,
        ])
        ->values()
        ->all();

    foreach ($publicFollowUps as $followUp) {
        Assert::assertIsString($followUp['follow_up'], "{$followUp['operation']} must name its PHP SDK follow-up.");
        Assert::assertNotSame(
            '',
            trim($followUp['follow_up']),
            "{$followUp['operation']} must name its PHP SDK follow-up.",
        );
    }

    $internalOperations = collect($surfaceOperations)
        ->where('classification', 'internal_only')
        ->pluck('operation')
        ->values()
        ->all();

    Assert::assertSame([
        'POST /analytics/update',
        'POST /internal-executor/token/verify',
        'POST /events/process',
        'GET /solo/tools',
        'GET /solo/projects',
        'GET /solo/{operation}',
        'POST /solo/{operation}',
        'GET /update/artifacts/{operationRun}/{artifactKind}/{platform}',
    ], $internalOperations);
});

/**
 * @return array<string, mixed>
 */
function exportOpenApiSchemaForSdkSurface(): array
{
    $path = storage_path('framework/testing/openapi/gateway-openapi-sdk-surface.json');

    File::ensureDirectoryExists(dirname($path));
    File::delete($path);

    $exitCode = Artisan::call('scramble:export', [
        '--path' => $path,
    ]);

    Assert::assertSame(0, $exitCode);
    Assert::assertTrue(File::exists($path));

    return decodedJsonObject(File::get($path));
}

/**
 * @return array<string, mixed>
 */
function openApiSdkSurfaceContract(): array
{
    $path = base_path('openapi-sdk-surface.json');

    Assert::assertTrue(File::exists($path));

    return decodedJsonObject(File::get($path));
}

/**
 * @return array<string, mixed>
 */
function decodedJsonObject(string $json): array
{
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    Assert::assertIsArray($decoded);

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * @param  array<string, mixed>  $surface
 * @return list<array{operation: string, classification: string, follow_up?: string}>
 */
function openApiSdkSurfaceOperations(array $surface): array
{
    /** @var list<array{operation: string, classification: string, follow_up?: string}>|null $operations */
    $operations = $surface['schema_only_operations'] ?? null;

    Assert::assertIsArray($operations);

    return $operations;
}

/**
 * @param  array<string, mixed>  $schema
 * @return list<array{operation: string, operation_id: string}>
 */
function schemaOperations(array $schema): array
{
    $operations = [];
    /** @var array<string, array<string, array<string, mixed>>>|null $paths */
    $paths = $schema['paths'] ?? null;

    Assert::assertIsArray($paths);

    foreach ($paths as $path => $methods) {
        Assert::assertIsArray($methods);

        foreach ($methods as $method => $operation) {
            $method = strtoupper($method);

            if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                continue;
            }

            Assert::assertIsArray($operation);

            $operations[] = [
                'operation' => "{$method} {$path}",
                'operation_id' => (string) ($operation['operationId'] ?? ''),
            ];
        }
    }

    return $operations;
}

/**
 * @return list<string>
 */
function coveredSdkOperations(): array
{
    $sdkRequestsPath = realpath(base_path('../../packages/sdk/src/Requests'));

    Assert::assertIsString($sdkRequestsPath);

    $operations = [];

    foreach (File::allFiles($sdkRequestsPath) as $file) {
        $class = sdkRequestClass($file->getPathname());

        if ($class === null) {
            continue;
        }

        $request = instantiateSdkRequest($class);

        if ($request === null) {
            continue;
        }

        $operations[] = sdkOperation($request);
    }

    return array_values(array_unique($operations));
}

/**
 * @return class-string<GatewayRequest>|null
 */
function sdkRequestClass(string $path): ?string
{
    $contents = File::get($path);
    $namespace = [];
    $class = [];

    if (! preg_match('/namespace ([^;]+);/', $contents, $namespace)) {
        return null;
    }

    if (! preg_match('/(?:final )?class (\w+)/', $contents, $class)) {
        return null;
    }

    $requestClass = "{$namespace[1]}\\{$class[1]}";

    if (! is_subclass_of($requestClass, GatewayRequest::class)) {
        return null;
    }

    return $requestClass;
}

/**
 * @param  class-string<GatewayRequest>  $class
 */
function instantiateSdkRequest(string $class): ?GatewayRequest
{
    try {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $arguments = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }

                $arguments[] = dummySdkArgument($parameter->getType(), $parameter->getName());
            }
        }

        $request = $reflection->newInstanceArgs($arguments);
    } catch (Throwable) {
        return null;
    }

    if (! $request instanceof GatewayRequest) {
        return null;
    }

    return $request;
}

function dummySdkArgument(?ReflectionType $type, string $name): mixed
{
    if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
        $typeName = $type->getName();

        if (enum_exists($typeName)) {
            return $typeName::cases()[0];
        }

        return null;
    }

    if (! $type instanceof ReflectionNamedType) {
        return $name;
    }

    return match ($type->getName()) {
        'int' => 1,
        'bool' => true,
        'array' => [],
        default => $name,
    };
}

function sdkOperation(GatewayRequest $request): string
{
    $endpoint = preg_replace('#^/api#', '', $request->resolveEndpoint());

    return "{$request->getMethod()->value} {$endpoint}";
}

/**
 * @param  list<string>  $sdkOperations
 */
function sdkCoversOperation(array $sdkOperations, string $schemaOperation): bool
{
    [$schemaMethod, $schemaPath] = explode(' ', $schemaOperation, 2);

    foreach ($sdkOperations as $sdkOperation) {
        [$sdkMethod, $sdkPath] = explode(' ', $sdkOperation, 2);

        if ($schemaMethod !== $sdkMethod) {
            continue;
        }

        if (pathShapeMatches($schemaPath, $sdkPath)) {
            return true;
        }
    }

    return false;
}

function pathShapeMatches(string $schemaPath, string $sdkPath): bool
{
    $schemaSegments = explode('/', trim($schemaPath, '/'));
    $sdkSegments = explode('/', trim($sdkPath, '/'));

    if (count($schemaSegments) !== count($sdkSegments)) {
        return false;
    }

    foreach ($schemaSegments as $index => $schemaSegment) {
        if (str_starts_with($schemaSegment, '{') && str_ends_with($schemaSegment, '}')) {
            continue;
        }

        if ($schemaSegment !== $sdkSegments[$index]) {
            return false;
        }
    }

    return true;
}
