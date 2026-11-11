<?php

declare(strict_types=1);

describe('the generated reachable Conn Project directory', function (): void {
    it('keeps the Project fields and adds typed cloneability and suffix fields', function (): void {
        $spec = json_decode((string) file_get_contents(dirname(base_path(), 2).'/docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
        $operation = $spec['paths']['/api/v1/conn/projects']['get'];
        $data = $operation['responses']['200']['content']['application/json']['schema']['properties']['data'];

        expect($operation['operationId'])->toBe('conn-project-list')
            ->and(collect($operation['parameters'])->firstWhere('name', 'include_unavailable'))->toMatchArray(['in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']])
            ->and($operation['responses'])->toHaveKey('422')
            ->and($data['items']['$ref'])->toBe('#/components/schemas/ConnProject');
        $properties = $spec['components']['schemas']['ConnProject']['properties'];
        expect(array_keys($properties))->toBe(['id', 'slug', 'name', 'node_id', 'node_name', 'instance_domain_suffix', 'cloneable', 'cloneable_reason', 'checks'])
            ->and($properties['node_id']['type'])->toBe(['integer', 'null'])
            ->and($properties['node_name']['type'])->toBe(['string', 'null'])
            ->and($properties['instance_domain_suffix']['type'])->toBe(['string', 'null'])
            ->and($properties['cloneable']['type'])->toBe('boolean')
            ->and($properties['cloneable_reason']['type'])->toBe(['string', 'null'])
            ->and($properties['checks']['type'])->toBe('array')
            ->and($properties['checks']['items']['$ref'])->toBe('#/components/schemas/ConnProjectCheck');
        $check = $spec['components']['schemas']['ConnProjectCheck']['properties'];
        expect($check['status']['enum'])->toBe(['pass', 'fail', 'not_checked'])
            ->and($check['label']['type'])->toBe('string')
            ->and($check['key'])->toBe(['type' => 'string'])
            ->and($check['code']['type'])->toBe('string')
            ->and($check['message']['type'])->toBe('string');
    });

    it('describes the wider scope and the cloneability limit on the MCP read', function (): void {
        $root = dirname(base_path(), 2);
        $catalogue = json_decode((string) file_get_contents($root.'/apps/gateway/resources/mcp/tools.json'), true, flags: JSON_THROW_ON_ERROR);
        $tool = collect($catalogue['tools'])->firstWhere('name', 'conn-project-list');

        expect($tool['method'])->toBe('GET')
            ->and($tool['path'])->toBe('/api/v1/conn/projects')
            ->and($tool['extension'])->toBe('conn')
            ->and($tool['starts_operation'])->toBeFalse()
            ->and($tool['query_inputs'])->toBe(['include_unavailable'])
            ->and($tool['input_schema']['properties']['include_unavailable']['type'])->toBe('boolean')
            ->and($tool['input_schema']['required'] ?? [])->not->toContain('include_unavailable')
            ->and($tool['description'])->toContain('project:list', 'instance_domain_suffix', 'cloneable_reason', 'host bridge', 'shared-folder', 'create is the guard');
    });
});
