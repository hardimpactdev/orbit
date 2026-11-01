<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Http\Mcp\ToolManifest;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route as RouteRegistry;
use Illuminate\Testing\TestResponse;
use Tests\Support\TaskModelRouter;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'mcp-schema-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.71',
        'wireguard_ip' => '10.44.0.71',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

describe('MCP tool input schemas', function (): void {
    it('serves every strict boolean API field as a boolean', function (): void {
        $schemas = mcp781_served_schemas($this);
        $fields = mcp781_strict_boolean_inputs();
        $failures = [];

        expect($fields)->not->toBeEmpty();

        foreach ($fields as $input) {
            $schema = $schemas[$input['tool']] ?? null;
            $type = is_array($schema) ? mcp781_schema_type($schema, $input['field']) : null;

            if ($type !== ['boolean']) {
                $failures[] = $input['tool'].'.'.$input['field'].' type is '.json_encode($type);
            }
        }

        expect($failures)->toBe([]);
    });

    it('serves a valid input schema for every tool', function (): void {
        TaskModelRouter::ready();
        $this->postJson('/api/v1/extensions/tasks/enable')->assertOk();

        $schemas = [];
        $cursor = null;

        do {
            $response = mcp781_call($this, 'tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            $response->assertOk();
            $document = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
            $tools = $document->result->tools ?? null;

            expect($tools)->toBeArray();

            foreach ($tools as $tool) {
                expect($tool)->toBeObject();
                $schemas[$tool->name] = $tool->inputSchema;
            }

            $cursor = $document->result->nextCursor ?? null;
        } while (is_string($cursor));

        $failures = [];

        foreach ($schemas as $name => $schema) {
            mcp782_check_schema($schema, $name, $failures);
        }

        foreach ([
            'tasks-create' => ['backlog', 'todo'],
            'tasks-update' => ['backlog', 'todo', 'reserved', 'running', 'reviewing', 'settling', 'waiting_for_review', 'completed', 'failed', 'cancelled'],
            'tasks-subtask-update' => ['todo', 'reserved', 'running', 'reviewing', 'completed', 'failed', 'cancelled'],
        ] as $name => $statuses) {
            if (! array_key_exists($name, $schemas)) {
                $failures[] = "{$name} is not served";
            }
            $enum = $schemas[$name]->properties->status->enum ?? null;

            if ($enum !== $statuses) {
                $failures[] = "{$name} status enum is ".json_encode($enum);
            }
        }

        expect($schemas)->not->toBeEmpty()
            ->and($failures)->toBe([]);
    });
});

/** @param array<string, mixed> $params */
function mcp781_call(mixed $test, string $method, array $params = []): TestResponse
{
    return $test->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => (object) $params,
    ]);
}

/** @return array<string, mixed> */
function mcp781_tool_payload(TestResponse $response): array
{
    $payload = json_decode((string) $response->json('result.content.0.text'), true);

    return is_array($payload) ? $payload : [];
}

/**
 * Tool names and fields whose form request rules call strictBoolean or strictTrue.
 *
 * @return list<array{tool: string, field: string}>
 */
function mcp781_strict_boolean_inputs(): array
{
    $inputs = [];
    $seen = [];

    foreach (RouteRegistry::getRoutes() as $route) {
        if (! $route instanceof RoutingRoute || ! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            continue;
        }

        [$controller, $method] = explode('@', $action, 2);

        if (! class_exists($controller) || ! method_exists($controller, $method)) {
            continue;
        }

        $fields = mcp781_request_fields(new ReflectionMethod($controller, $method));

        if ($fields === []) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $http) {
            $tool = mcp781_tool_name($http, $route->uri());

            expect($tool)->not->toBeNull("{$http} {$route->uri()} has no MCP tool");

            foreach ($fields as $field) {
                $key = $tool.'.'.$field;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $inputs[] = ['tool' => $tool, 'field' => $field];
            }
        }
    }

    return $inputs;
}

/** @return list<string> */
function mcp781_request_fields(ReflectionMethod $action): array
{
    $fields = [];

    foreach ($action->getParameters() as $parameter) {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            continue;
        }

        $class = $type->getName();

        if (! is_a($class, FormRequest::class, true) || ! method_exists($class, 'rules')) {
            continue;
        }

        $rules = new ReflectionMethod($class, 'rules');
        $file = $rules->getFileName();

        if (! is_string($file)) {
            continue;
        }

        $fields = [...$fields, ...mcp781_strict_boolean_fields(mcp781_rules_body((string) file_get_contents($file)))];
    }

    return array_values(array_unique($fields));
}

function mcp781_rules_body(string $source): string
{
    $start = strpos($source, 'function rules(');

    if ($start === false) {
        return '';
    }

    $open = strpos($source, '{', $start);

    if ($open === false) {
        return '';
    }

    return mcp781_balanced($source, $open, '{', '}');
}

/** @return list<string> */
function mcp781_strict_boolean_fields(string $rulesBody): array
{
    $fields = [];
    $length = strlen($rulesBody);

    for ($i = 0; $i < $length; $i++) {
        if ($rulesBody[$i] !== "'") {
            continue;
        }

        $end = strpos($rulesBody, "'", $i + 1);

        if ($end === false) {
            break;
        }

        $key = substr($rulesBody, $i + 1, $end - $i - 1);
        $cursor = $end + 1;

        if (preg_match('/\A\s*=>\s*/', substr($rulesBody, $cursor), $match) !== 1) {
            $i = $end;

            continue;
        }

        $valueStart = $cursor + strlen($match[0]);

        if (($rulesBody[$valueStart] ?? '') !== '[') {
            $i = $end;

            continue;
        }

        $value = mcp781_balanced($rulesBody, $valueStart, '[', ']');

        if (preg_match('/\bstrict(?:Boolean|True)\s*\(/', $value) === 1) {
            $fields[] = $key;
        }

        $i = $valueStart + strlen($value) - 1;
    }

    return $fields;
}

function mcp781_balanced(string $source, int $start, string $open, string $close): string
{
    $depth = 0;
    $length = strlen($source);
    $inString = false;
    $escape = false;

    for ($i = $start; $i < $length; $i++) {
        $character = $source[$i];

        if ($inString) {
            if ($escape) {
                $escape = false;

                continue;
            }

            if ($character === '\\') {
                $escape = true;

                continue;
            }

            if ($character === "'") {
                $inString = false;
            }

            continue;
        }

        if ($character === "'") {
            $inString = true;

            continue;
        }

        if ($character === $open) {
            $depth++;
        } elseif ($character === $close) {
            $depth--;

            if ($depth === 0) {
                return substr($source, $start + ($open === '{' ? 1 : 0), $i - $start - ($open === '{' ? 1 : 0));
            }
        }
    }

    return '';
}

function mcp781_tool_name(string $method, string $uri): ?string
{
    $path = '/'.ltrim($uri, '/');

    foreach (ToolManifest::default()->definitions() as $definition) {
        if ($definition->method === strtoupper($method) && '/'.ltrim($definition->path, '/') === $path) {
            return $definition->name;
        }
    }

    return null;
}

/**
 * A served JSON Schema must keep object keywords as objects. PHP's decoded array cannot tell {} from [].
 *
 * @param  list<string>  $failures
 */
function mcp782_check_schema(mixed $schema, string $path, array &$failures): void
{
    if (is_bool($schema)) {
        return;
    }

    if (is_array($schema) || ! $schema instanceof stdClass) {
        $failures[] = "{$path} is not an object";

        return;
    }

    if (property_exists($schema, 'enum')) {
        $enum = $schema->enum;

        if (! is_array($enum) || $enum === [] || ! array_is_list($enum)) {
            $failures[] = "{$path}.enum is empty";
        }
    }

    foreach (['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'] as $keyword) {
        if (! property_exists($schema, $keyword)) {
            continue;
        }

        $value = $schema->{$keyword};

        if (! $value instanceof stdClass) {
            $failures[] = "{$path}.{$keyword} is not an object";

            continue;
        }

        foreach (get_object_vars($value) as $name => $child) {
            mcp782_check_schema($child, "{$path}.{$keyword}.{$name}", $failures);
        }
    }

    foreach (['items', 'additionalProperties', 'unevaluatedProperties', 'unevaluatedItems', 'contains', 'propertyNames', 'if', 'then', 'else', 'not', 'contentSchema'] as $keyword) {
        if (! property_exists($schema, $keyword) || is_bool($schema->{$keyword})) {
            continue;
        }

        mcp782_check_schema($schema->{$keyword}, "{$path}.{$keyword}", $failures);
    }

    foreach (['oneOf', 'anyOf', 'allOf', 'prefixItems'] as $keyword) {
        if (! property_exists($schema, $keyword)) {
            continue;
        }

        $value = $schema->{$keyword};

        if (! is_array($value) || ! array_is_list($value)) {
            $failures[] = "{$path}.{$keyword} is not a list";

            continue;
        }

        foreach ($value as $index => $child) {
            mcp782_check_schema($child, "{$path}.{$keyword}.{$index}", $failures);
        }
    }
}

/** @return array<string, array<string, mixed>> */
function mcp781_served_schemas(mixed $test): array
{
    $schemas = [];
    $cursor = null;

    do {
        $response = mcp781_call($test, 'tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
        $response->assertOk();

        foreach ($response->json('result.tools') as $tool) {
            if (is_array($tool) && is_string($tool['name'] ?? null) && is_array($tool['inputSchema'] ?? null)) {
                $schemas[$tool['name']] = $tool['inputSchema'];
            }
        }

        $cursor = $response->json('result.nextCursor');
    } while (is_string($cursor));

    return $schemas;
}

/** @param array<string, mixed> $schema
 * @return list<mixed>|null
 */
function mcp781_schema_type(array $schema, string $field): ?array
{
    $node = $schema;

    foreach (explode('.', $field) as $part) {
        if ($part === '*') {
            $items = $node['items'] ?? null;
            if (! is_array($items)) {
                return null;
            }
            $node = $items;

            continue;
        }

        $properties = $node['properties'] ?? null;

        if (! is_array($properties) || ! is_array($properties[$part] ?? null)) {
            return null;
        }

        $node = $properties[$part];
    }

    $type = $node['type'] ?? null;
    $types = array_values(array_filter(
        is_array($type) ? $type : [$type],
        static fn (mixed $item): bool => $item !== 'null',
    ));

    return $types;
}
