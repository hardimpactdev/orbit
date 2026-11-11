<?php

declare(strict_types=1);

describe('the generated Conn Instance name check contract', function (): void {
    it('publishes a required string query, typed reasons and the read refusals', function (): void {
        $spec = json_decode((string) file_get_contents(dirname(base_path(), 2).'/docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
        $operation = $spec['paths']['/api/v1/conn/projects/{project}/instances/check']['get'];
        $name = collect($operation['parameters'])->firstWhere('name', 'name');

        expect($operation['operationId'])->toBe('conn-instance-name-check')
            ->and($name)->toMatchArray(['in' => 'query', 'required' => true, 'schema' => ['type' => 'string']])
            ->and($operation['responses'])->toHaveKeys(['200', '403', '404', '409', '422'])
            ->and($operation['responses']['200']['content']['application/json']['schema']['properties']['data']['$ref'])
            ->toBe('#/components/schemas/ConnInstanceNameCheck');
        $properties = $spec['components']['schemas']['ConnInstanceNameCheck']['properties'];
        expect(array_keys($properties))->toBe(['name', 'available', 'reason', 'domain'])
            ->and($properties['available']['type'])->toBe('boolean')
            ->and($properties['reason']['enum'])->toBe(['taken', 'invalid', 'default', null])
            ->and($properties['domain']['type'])->toBe(['string', 'null']);
    });

    it('generates a strict MCP read from the same endpoint without constraining name availability', function (): void {
        $root = dirname(base_path(), 2);
        $catalogue = json_decode((string) file_get_contents($root.'/apps/gateway/resources/mcp/tools.json'), true, flags: JSON_THROW_ON_ERROR);
        $tool = collect($catalogue['tools'])->firstWhere('name', 'conn-instance-name-check');

        expect($tool['method'])->toBe('GET')
            ->and($tool['path'])->toBe('/api/v1/conn/projects/{project}/instances/check')
            ->and($tool['extension'])->toBe('conn')
            ->and($tool['starts_operation'])->toBeFalse()
            ->and($tool['query_inputs'])->toBe(['name'])
            ->and($tool['input_schema']['properties']['name'])->toBe(['type' => 'string'])
            ->and($tool['input_schema']['required'])->toBe(['project', 'name'])
            ->and($tool['input_schema']['additionalProperties'])->toBeFalse();
    });

    it('preserves create and clone schemas after extracting the shared name validator', function (): void {
        $spec = json_decode((string) file_get_contents(dirname(base_path(), 2).'/docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['/api/v1/instances', '/api/v1/conn/projects/{project}/instances', '/api/v1/instances/{candidate}/clone'] as $path) {
            $body = $spec['paths'][$path]['post']['requestBody']['content']['application/json']['schema'];

            expect($body['required'])->toContain('name')
                ->and($body['properties']['name']['type'])->toBe('string')
                ->and($body['properties']['name']['maxLength'])->toBe(63)
                ->and($body['properties']['name']['pattern'])->not->toBeEmpty();
        }
    });
});
