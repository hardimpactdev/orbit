<?php

declare(strict_types=1);

use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

require_once __DIR__.'/NodeRoleCommandTestHelpers.php';

describe('node role:list', function (): void {
    it('lists one node role assignments in json', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        NodeRoleAssignment::query()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => 'error',
            'settings' => ['tld' => 'test'],
            'last_error' => 'DNS mapping failed.',
            'converged_at' => null,
        ]);

        assignNodeRole($node, 'database');

        $exitCode = Artisan::call('node role:list', [
            'node' => 'client-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'node' => 'client-1',
                        'roles' => [
                            nodeRoleAssignmentPayload('app-development', 'error', ['tld' => 'test'], 'DNS mapping failed.', null),
                            nodeRoleAssignmentPayload('database', 'active', [], null, NodeRoleAssignment::query()->where('node_id', $node->id)->where('role', 'database')->value('converged_at')?->toJSON()),
                        ],
                    ],
                ],
            ]);
    });

    it('lists one node role assignments in human output', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        assignNodeRole($node, 'app-development', settings: ['tld' => 'test']);

        $exitCode = Artisan::call('node role:list', [
            'node' => 'client-1',
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('ROLE')
            ->and($output)->toContain('STATUS')
            ->and($output)->toContain('SETTINGS')
            ->and($output)->toContain('app-development')
            ->and($output)->toContain('test');
    });

    it('fails when the node is missing in non-interactive mode', function (): void {
        setupNodeRoleGatewayCaller();

        $exitCode = Artisan::call('node role:list', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('node');
    });
});
