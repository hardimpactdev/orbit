<?php

declare(strict_types=1);

describe('the generated Conn held-session contract', function (): void {
    it('documents the session read as an unkeyed Operation start with a held session or an Operation', function (): void {
        $spec = json_decode((string) file_get_contents(dirname(base_path(), 2).'/docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
        $operation = $spec['paths']['/api/v1/conn/environments/{environment}/session']['get'];
        $data = fn (string $status): array => $operation['responses'][$status]['content']['application/json']['schema']['properties']['data'];

        expect($operation['operationId'])->toBe('conn-environment-session')
            ->and($operation['x-orbit-operation'])->toBe('start')
            ->and(collect($operation['parameters'])->pluck('name')->all())->not->toContain('Idempotency-Key')
            ->and($data('200')['$ref'])->toBe('#/components/schemas/ConnHeldSession')
            ->and($data('202')['$ref'])->toBe('#/components/schemas/Operation')
            ->and($operation['responses'])->toHaveKeys(['403', '409'])
            ->and($operation['responses']['409']['description'])->toContain('conn.environment_not_instance', 'conn.environment_asleep', 'conn.session_expired', 'instance.removal_conflict')
            ->and($operation['responses']['409']['description'])->not->toContain('operation.idempotency_conflict')
            ->and($operation['responses'])->not->toHaveKey('422');
    });

    it('documents include_held on the pairing list and its validation failure', function (): void {
        $spec = json_decode((string) file_get_contents(dirname(base_path(), 2).'/docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
        $operation = $spec['paths']['/api/v1/conn/environments/{environment}/pairings']['get'];

        expect($operation['operationId'])->toBe('conn-pairing-list')
            ->and(collect($operation['parameters'])->firstWhere('name', 'include_held'))->toMatchArray(['in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']])
            ->and($operation['responses'])->toHaveKey('422');
    });

    it('generates the MCP tools from the same endpoints', function (): void {
        $catalogue = json_decode((string) file_get_contents(dirname(base_path(), 2).'/apps/gateway/resources/mcp/tools.json'), true, flags: JSON_THROW_ON_ERROR);
        $session = collect($catalogue['tools'])->firstWhere('name', 'conn-environment-session');
        $pairings = collect($catalogue['tools'])->firstWhere('name', 'conn-pairing-list');

        expect($session['method'])->toBe('GET')
            ->and($session['path'])->toBe('/api/v1/conn/environments/{environment}/session')
            ->and($session['extension'])->toBe('conn')
            ->and($session['starts_operation'])->toBeTrue()
            ->and($pairings['query_inputs'])->toBe(['include_held'])
            ->and($pairings['input_schema']['properties']['include_held']['type'])->toBe('boolean')
            ->and($pairings['input_schema']['required'])->not->toContain('include_held');
    });
});
