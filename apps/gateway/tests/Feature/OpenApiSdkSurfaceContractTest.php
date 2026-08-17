<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Orbit\Sdk\Laravel\GatewayRequest;
use PHPUnit\Framework\Assert;

test('gateway openapi schema-only operations are classified for sdk generation', function (): void {
    ini_set('memory_limit', '1G');

    $schema = export_open_api_schema_for_sdk_surface();
    $surface = open_api_sdk_surface_contract();
    $sdkOperations = covered_sdk_operations();
    $surfaceOperations = open_api_sdk_surface_operations($surface);

    $schemaOperations = collect(schema_operations($schema));

    Assert::assertSame(174, $schemaOperations->count());

    $schemaOnlyOperations = $schemaOperations
        ->reject(fn (array $operation): bool => sdk_covers_operation($sdkOperations, $operation['operation']))
        ->values();

    Assert::assertSame(74, $schemaOnlyOperations->count());

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
        'internal_only' => 14,
        'deferred_optional' => 37,
        'public_sdk' => 23,
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
        'GET /operations/{operationRun}/stream',
        'POST /operations/{operationRun}/stream/auth',
        'POST /operations/{operationRun}/stream/leave',
        'GET /operations/{operationRun}/stream/stop-decision',
        'POST /operations/{operationRun}/stream/publisher-credentials',
        'POST /operations/{operationRun}/stream/publish',
        'GET /runtime-activations/{type}/{id}',
        'GET /solo/tools',
        'GET /solo/projects',
        'GET /solo/{operation}',
        'POST /solo/{operation}',
        'GET /update/artifacts/{operationRun}/{artifactKind}/{platform}',
    ], $internalOperations);
});

test('application log openapi documents lines node and required workspace instance', function (): void {
    ini_set('memory_limit', '1G');

    $schema = export_open_api_schema_for_sdk_surface();
    /** @var array<string, mixed> $paths */
    $paths = $schema['paths'];

    $instanceLog = $paths['/instances/{instance}/log']['get'] ?? null;
    Assert::assertIsArray($instanceLog);
    Assert::assertSame('instanceApplicationLog', $instanceLog['operationId'] ?? null);
    Assert::assertTrue(openapi_has_query_parameter($instanceLog, 'lines', required: false, type: 'integer'));
    Assert::assertTrue(openapi_has_query_parameter($instanceLog, 'node', required: false, type: 'string'));

    $instanceStream = $paths['/instances/{instance}/log-stream']['post'] ?? null;
    Assert::assertIsArray($instanceStream);
    Assert::assertSame('instanceApplicationLogStreamStart', $instanceStream['operationId'] ?? null);
    Assert::assertTrue(openapi_request_body_has_property($instanceStream, 'lines', required: false, type: 'integer'));
    Assert::assertTrue(openapi_request_body_has_property($instanceStream, 'node', required: false, type: 'string'));
    Assert::assertFalse(openapi_request_body_requires_property($instanceStream, 'instance'));

    $workspaceLog = $paths['/workspaces/{workspace}/log']['get'] ?? null;
    Assert::assertIsArray($workspaceLog);
    Assert::assertSame('workspaceApplicationLog', $workspaceLog['operationId'] ?? null);
    Assert::assertTrue(openapi_has_query_parameter($workspaceLog, 'instance', required: true, type: 'string'));
    Assert::assertTrue(openapi_has_query_parameter($workspaceLog, 'lines', required: false, type: 'integer'));
    Assert::assertTrue(openapi_has_query_parameter($workspaceLog, 'node', required: false, type: 'string'));

    $workspaceStream = $paths['/workspaces/{workspace}/log-stream']['post'] ?? null;
    Assert::assertIsArray($workspaceStream);
    Assert::assertSame('workspaceApplicationLogStreamStart', $workspaceStream['operationId'] ?? null);
    Assert::assertTrue(openapi_request_body_has_property($workspaceStream, 'instance', required: true, type: 'string'));
    Assert::assertTrue(openapi_request_body_has_property($workspaceStream, 'lines', required: false, type: 'integer'));
    Assert::assertTrue(openapi_request_body_has_property($workspaceStream, 'node', required: false, type: 'string'));
    Assert::assertTrue(openapi_request_body_requires_property($workspaceStream, 'instance'));
});

/**
 * @return array<string, mixed>
 */
function export_open_api_schema_for_sdk_surface(): array
{
    $path = storage_path('framework/testing/openapi/gateway-openapi-sdk-surface.json');

    File::ensureDirectoryExists(dirname($path));
    File::delete($path);

    $exitCode = Artisan::call('scramble:export', [
        '--path' => $path,
    ]);

    Assert::assertSame(0, $exitCode);
    Assert::assertTrue(File::exists($path));

    return decoded_json_object(File::get($path));
}

/**
 * @return array<string, mixed>
 */
function open_api_sdk_surface_contract(): array
{
    $path = base_path('openapi-sdk-surface.json');

    Assert::assertTrue(File::exists($path));

    return decoded_json_object(File::get($path));
}

/**
 * @return array<string, mixed>
 */
function decoded_json_object(string $json): array
{
    $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

    Assert::assertIsArray($decoded);

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * @param  array<string, mixed>  $surface
 * @return list<array{operation: string, classification: string, follow_up?: string}>
 */
function open_api_sdk_surface_operations(array $surface): array
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
function schema_operations(array $schema): array
{
    $operations = [];
    /** @var array<string, array<string, array<string, mixed>>>|null $paths */
    $paths = $schema['paths'] ?? null;

    Assert::assertIsArray($paths);

    foreach ($paths as $path => $methods) {
        Assert::assertIsArray($methods);

        foreach ($methods as $method => $operation) {
            $method = strtoupper($method);

            if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], strict: true)) {
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
function covered_sdk_operations(): array
{
    $sdkRequestsPath = realpath(base_path('../../packages/sdk/src/Requests'));

    Assert::assertIsString($sdkRequestsPath);

    $operations = [];

    foreach (File::allFiles($sdkRequestsPath) as $file) {
        $class = sdk_request_class($file->getPathname());

        if ($class === null) {
            continue;
        }

        $request = instantiate_sdk_request($class);

        if ($request === null) {
            continue;
        }

        $operations[] = sdk_operation($request);
    }

    return array_values(array_unique($operations));
}

/**
 * @return class-string<GatewayRequest>|null
 */
function sdk_request_class(string $path): ?string
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
function instantiate_sdk_request(string $class): ?GatewayRequest
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

                $arguments[] = dummy_sdk_argument($parameter->getType(), $parameter->getName());
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

function dummy_sdk_argument(?ReflectionType $type, string $name): mixed
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

function sdk_operation(GatewayRequest $request): string
{
    $endpoint = preg_replace(pattern: '#^/api#', replacement: '', subject: $request->resolveEndpoint());

    return "{$request->getMethod()->value} {$endpoint}";
}

/**
 * @param  list<string>  $sdkOperations
 */
function sdk_covers_operation(array $sdkOperations, string $schemaOperation): bool
{
    [$schemaMethod, $schemaPath] = explode(separator: ' ', string: $schemaOperation, limit: 2);

    foreach ($sdkOperations as $sdkOperation) {
        [$sdkMethod, $sdkPath] = explode(separator: ' ', string: $sdkOperation, limit: 2);

        if ($schemaMethod !== $sdkMethod) {
            continue;
        }

        if (path_shape_matches($schemaPath, $sdkPath)) {
            return true;
        }
    }

    return false;
}

function path_shape_matches(string $schemaPath, string $sdkPath): bool
{
    $schemaSegments = explode(separator: '/', string: trim($schemaPath, characters: '/'));
    $sdkSegments = explode(separator: '/', string: trim($sdkPath, characters: '/'));

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

/**
 * @param  array<string, mixed>  $operation
 */
function openapi_has_query_parameter(array $operation, string $name, bool $required, string $type): bool
{
    $parameters = $operation['parameters'] ?? null;

    if (! is_array($parameters)) {
        return false;
    }

    foreach ($parameters as $parameter) {
        if (! is_array($parameter)) {
            continue;
        }

        if (($parameter['name'] ?? null) !== $name || ($parameter['in'] ?? null) !== 'query') {
            continue;
        }

        $schema = $parameter['schema'] ?? null;
        $schemaType = is_array($schema) ? $schema['type'] ?? null : null;

        return ($parameter['required'] ?? false) === $required && $schemaType === $type;
    }

    return false;
}

/**
 * @param  array<string, mixed>  $operation
 */
function openapi_request_body_has_property(array $operation, string $name, bool $required, string $type): bool
{
    $schema = openapi_request_body_schema($operation);

    if ($schema === null) {
        return false;
    }

    $properties = $schema['properties'] ?? null;

    if (! is_array($properties) || ! is_array($properties[$name] ?? null)) {
        return false;
    }

    $property = $properties[$name];
    $requiredNames = $schema['required'] ?? [];

    if (! is_array($requiredNames)) {
        $requiredNames = [];
    }

    $isRequired = in_array($name, $requiredNames, true);

    return $isRequired === $required && ($property['type'] ?? null) === $type;
}

/**
 * @param  array<string, mixed>  $operation
 */
function openapi_request_body_requires_property(array $operation, string $name): bool
{
    $schema = openapi_request_body_schema($operation);

    if ($schema === null) {
        return false;
    }

    $requiredNames = $schema['required'] ?? [];

    return is_array($requiredNames) && in_array($name, $requiredNames, true);
}

/**
 * @param  array<string, mixed>  $operation
 * @return array<string, mixed>|null
 */
function openapi_request_body_schema(array $operation): ?array
{
    $requestBody = $operation['requestBody'] ?? null;

    if (! is_array($requestBody)) {
        return null;
    }

    $content = $requestBody['content']['application/json'] ?? null;

    if (! is_array($content)) {
        return null;
    }

    $schema = $content['schema'] ?? null;

    return is_array($schema) ? $schema : null;
}
