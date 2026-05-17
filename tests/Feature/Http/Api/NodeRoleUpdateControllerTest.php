<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/NodeRoleApiTestHelpers.php';

describe('NodeRoleUpdateController', function (): void {
    it('updates a role for an authorized control caller and returns the assignment payload', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess();
        createNodeRoleApiContractAssignment($target, 'app-development', settings: ['tld' => 'old']);

        $response = patchNodeRoleApiContractJson('/api/nodes/target-1/roles/app-development', [
            'settings' => ['tld' => 'test'],
        ]);

        $response->assertOk()
            ->assertJsonPath('success.data.node', 'target-1')
            ->assertJsonPath('success.data.assignment.role', 'app-development')
            ->assertJsonPath('success.data.assignment.status', 'active')
            ->assertJsonPath('success.data.assignment.settings.tld', 'test')
            ->assertJsonPath('success.data.assignment.last_error', null);

        expect($target->roleAssignments()->where('role', 'app-development')->first()?->settings)
            ->toBe(['tld' => 'test']);
    });

    it('rejects gateway role updates before side effects', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess();
        createNodeRoleApiContractAssignment($target, 'gateway');

        $response = patchNodeRoleApiContractJson('/api/nodes/target-1/roles/gateway', [
            'settings' => [],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'The gateway role cannot be managed through node role commands.')
            ->assertJsonPath('error.meta.field', 'role')
            ->assertJsonPath('error.meta.role', 'gateway')
            ->assertJsonMissingPath('success');

        expect($target->roleAssignments()->where('role', 'gateway')->exists())->toBeTrue();
    });

    it('returns the authorized control caller response shape for database updates without settings', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess();
        createNodeRoleApiContractAssignment($target, 'database');

        $response = patchNodeRoleApiContractJson('/api/nodes/target-1/roles/database');

        $response->assertOk()
            ->assertJsonStructure([
                'success' => [
                    'data' => [
                        'node',
                        'assignment' => [
                            'role',
                            'status',
                            'settings',
                            'last_error',
                            'converged_at',
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('success.data.node', 'target-1')
            ->assertJsonPath('success.data.assignment.role', 'database')
            ->assertJsonPath('success.data.assignment.settings', []);
    });
});
