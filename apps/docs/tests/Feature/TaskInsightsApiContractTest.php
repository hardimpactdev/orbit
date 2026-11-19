<?php

declare(strict_types=1);

describe('the generated task insights contract', function (): void {
    it('publishes the periods the endpoint accepts, in the API reference and the MCP tool', function (): void {
        $root = dirname(base_path(), 2);
        $spec = json_decode((string) file_get_contents($root.'/docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
        $period = collect($spec['paths']['/api/v1/task-insights']['get']['parameters'])->firstWhere('name', 'period');
        $catalogue = json_decode((string) file_get_contents($root.'/apps/gateway/resources/mcp/tools.json'), true, flags: JSON_THROW_ON_ERROR);
        $tool = collect($catalogue['tools'])->firstWhere('name', 'task-insights');

        expect($period['schema']['enum'])->toBe(['7d', '30d'])
            ->and($tool['input_schema']['properties']['period'])->toBe(['type' => 'string', 'enum' => ['7d', '30d']])
            ->and($tool['input_schema']['properties']['project_id'])->toBe(['type' => 'integer', 'minimum' => 1]);
    });
});
