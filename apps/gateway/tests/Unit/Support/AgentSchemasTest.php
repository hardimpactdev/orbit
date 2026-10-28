<?php

declare(strict_types=1);

use App\Infrastructure\AgentCommands\AgentSchemas;

/** A schema in the shape `schemars` writes: a tagged enum, a map, a list, and an unsigned integer. */
function agent_schema_example(): array
{
    return [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'title' => 'Input',
        'oneOf' => [
            ['type' => 'object', 'properties' => ['operation' => ['type' => 'string', 'const' => 'snapshot']], 'required' => ['operation'], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['operation' => ['type' => 'string', 'const' => 'apply'], 'state' => ['$ref' => '#/$defs/State']], 'required' => ['operation', 'state'], 'additionalProperties' => false],
        ],
        '$defs' => ['State' => ['type' => 'object', 'properties' => [
            'environment' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'rules' => ['type' => 'array', 'items' => ['type' => 'string']],
            'port' => ['type' => 'integer', 'format' => 'uint16', 'minimum' => 0, 'maximum' => 65535],
            'address' => ['type' => ['string', 'null'], 'format' => 'ipv4'],
        ], 'required' => ['environment', 'rules', 'port'], 'additionalProperties' => false]],
    ];
}

function agent_schema_wire(array $value): stdClass
{
    return json_decode(json_encode((object) $value, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
}

it('accepts a matching wire input and names each violation by its path', function (): void {
    $state = ['environment' => ['APP_ENV' => 'local'], 'rules' => ['allow'], 'port' => 8080, 'address' => '10.44.0.2'];

    expect(AgentSchemas::errors(agent_schema_wire(['operation' => 'apply', 'state' => $state]), agent_schema_example()))->toBe([])
        ->and(AgentSchemas::errors(agent_schema_wire(['operation' => 'snapshot']), agent_schema_example()))->toBe([])
        ->and(AgentSchemas::errors(agent_schema_wire(['operation' => 'snapshot', 'extra' => 1]), agent_schema_example()))->toBe(['$: matches 0 of oneOf'])
        ->and(AgentSchemas::errors(agent_schema_wire(['operation' => 'apply', 'state' => [...$state, 'port' => -1]]), agent_schema_example()))->toBe(['$: matches 0 of oneOf']);

    $variant = agent_schema_example()['oneOf'][1];
    $variant['$defs'] = agent_schema_example()['$defs'];
    expect(AgentSchemas::errors(agent_schema_wire(['operation' => 'apply', 'state' => [...$state, 'port' => 70000, 'address' => '999.1.1.1', 'unknown' => true]]), $variant))
        ->toBe(['$.state.port: above 65535', '$.state.address: not an ipv4 address', '$.state: unknown field [unknown]'])
        ->and(AgentSchemas::errors(agent_schema_wire(['operation' => 'apply', 'state' => ['rules' => [], 'port' => 1]]), $variant))
        ->toBe(['$.state: missing [environment]']);
});

it('refuses an empty PHP array where the agent expects a map, because it is sent as a JSON list', function (): void {
    $variant = agent_schema_example()['oneOf'][1];
    $variant['$defs'] = agent_schema_example()['$defs'];

    expect(AgentSchemas::errors(agent_schema_wire(['operation' => 'apply', 'state' => ['environment' => [], 'rules' => [], 'port' => 1]]), $variant))
        ->toBe(['$.state.environment: expected object, got array']);
});

it('accepts an empty array as an empty object in a Gateway answer, as json_decode gives it', function (): void {
    expect(AgentSchemas::errors([], ['type' => 'object', 'properties' => [], 'additionalProperties' => false]))->toBe([])
        ->and(AgentSchemas::errors(['a' => 1], ['type' => 'array']))->toBe(['$: expected array, got array']);
});

it('enforces the keywords it knows, as strictly as the agent decodes', function (): void {
    expect(AgentSchemas::errors(json_decode('{"n":5.0}', flags: JSON_THROW_ON_ERROR), ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]]))
        ->toBe(['$.n: expected integer, got float'])
        ->and(AgentSchemas::errors(['a', 'a'], ['type' => 'array', 'uniqueItems' => true]))->toBe(['$: items are not unique'])
        ->and(AgentSchemas::errors('abc', ['type' => 'string', 'pattern' => '^a/b$']))->toBe(['$: does not match ^a/b$'])
        ->and(AgentSchemas::errors('a/b', ['type' => 'string', 'pattern' => '^a/b$']))->toBe([])
        ->and(AgentSchemas::errors(0, ['type' => 'integer', 'exclusiveMinimum' => 0]))->toBe(['$: not above 0'])
        ->and(AgentSchemas::errors(['Bad' => 1], ['type' => 'object', 'propertyNames' => ['pattern' => '^[a-z]+$']]))->toBe(['$.Bad: does not match ^[a-z]+$']);
});

it('refuses a keyword it does not know instead of ignoring it', function (): void {
    expect(fn () => AgentSchemas::errors('x', ['type' => 'string', 'patternProperties' => []]))
        ->toThrow(LogicException::class, 'Unsupported JSON Schema keyword [patternProperties]');
});

it('knows every keyword and reference the exported command schemas use', function (): void {
    $document = json_decode((string) file_get_contents(AgentSchemas::Path), true, flags: JSON_THROW_ON_ERROR);
    $walk = static function (array|bool $schema, array $root) use (&$walk): void {
        if (is_bool($schema)) {
            return;
        }
        expect(array_diff(array_keys($schema), AgentSchemas::Keywords))->toBe([]);
        if (isset($schema['$ref'])) {
            expect($schema['$ref'])->toStartWith('#/$defs/')
                ->and($root['$defs'])->toHaveKey(substr($schema['$ref'], 8));
        }
        foreach (['properties', '$defs'] as $map) {
            foreach ($schema[$map] ?? [] as $child) {
                $walk($child, $root);
            }
        }
        foreach (['oneOf', 'anyOf', 'allOf', 'prefixItems'] as $list) {
            foreach ($schema[$list] ?? [] as $child) {
                $walk($child, $root);
            }
        }
        foreach (['items', 'additionalProperties', 'not'] as $single) {
            if (is_array($schema[$single] ?? null)) {
                $walk($schema[$single], $root);
            }
        }
    };

    expect($document['commands'])->not->toBeEmpty();
    foreach ($document['commands'] as $schemas) {
        $walk($schemas['input'], $schemas['input']);
        $walk($schemas['output'], $schemas['output']);
    }
});
