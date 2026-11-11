<?php

declare(strict_types=1);

describe('generated Conn environment contract', function (): void {
    it('exposes nullable flat Orbit facts on each environment record', function (): void {
        $spec = json_decode((string) file_get_contents(dirname(base_path(), 2).'/docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
        $operation = $spec['paths']['/api/v1/conn/environments']['get'];
        $items = $operation['responses']['200']['content']['application/json']['schema']['properties']['data']['items'];

        expect($operation['operationId'])->toBe('conn-environment-list')
            ->and($items['$ref'])->toBe('#/components/schemas/ConnEnvironment');
        $schema = $spec['components']['schemas']['ConnEnvironment'];
        foreach (['project_id' => 'integer', 'project_name' => 'string', 'project_slug' => 'string',
            'instance_name' => 'string', 'node_name' => 'string', 'instance_url' => 'string'] as $field => $type) {
            expect($schema['properties'][$field]['type'])->toBe([$type, 'null']);
        }
    });

    it('generates the MCP list tool from the same endpoint with the Orbit facts description', function (): void {
        $root = dirname(base_path(), 2);
        $catalogue = json_decode((string) file_get_contents($root.'/apps/gateway/resources/mcp/tools.json'), true, flags: JSON_THROW_ON_ERROR);
        $tool = collect($catalogue['tools'])->firstWhere('name', 'conn-environment-list');

        expect($tool['method'])->toBe('GET')
            ->and($tool['path'])->toBe('/api/v1/conn/environments')
            ->and($tool['extension'])->toBe('conn')
            ->and($tool['description'])->toContain('project_id', 'project_name', 'project_slug', 'instance_name', 'node_name', 'instance_url', 'stock');
    });
});
