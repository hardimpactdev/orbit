<?php

declare(strict_types=1);

use App\Console\Commands\NodeNewCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function nodeNewCallerRoleRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'local-node',
        'role' => 'control',
        'host' => '10.6.0.2',
        'ssh_user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function invokeNodeNewCallerRole(): string
{
    $command = app(NodeNewCommand::class);
    $reflection = new ReflectionMethod($command, 'callerRole');
    $reflection->setAccessible(true);

    return $reflection->invoke($command);
}

describe('NodeNewCommand::callerRole()', function (): void {
    beforeEach(function (): void {
        DB::table('nodes')->truncate();
    });

    it('returns control when no local active node exists', function (): void {
        expect(invokeNodeNewCallerRole())->toBe('control');
    });

    it('returns control when local active node has an empty role', function (): void {
        DB::table('nodes')->insert(nodeNewCallerRoleRow([
            'role' => '',
        ]));

        expect(invokeNodeNewCallerRole())->toBe('control');
    });

    it('round-trips allow-listed roles control, gateway, and app', function (): void {
        foreach (['control', 'gateway', 'app'] as $role) {
            DB::table('nodes')->truncate();
            DB::table('nodes')->insert(nodeNewCallerRoleRow([
                'role' => $role,
            ]));

            expect(invokeNodeNewCallerRole())
                ->toBe($role, "Expected callerRole() to return '{$role}' for role '{$role}'");
        }
    });

    it('returns unknown for unsupported role values', function (): void {
        DB::table('nodes')->insert(nodeNewCallerRoleRow([
            'role' => 'bogus',
        ]));

        expect(invokeNodeNewCallerRole())->toBe('unknown');
    });
});
