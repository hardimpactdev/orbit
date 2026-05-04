<?php

declare(strict_types=1);

use App\Console\Commands\DnsResolveTldCommand;
use App\Console\Commands\GatewayAddCommand;
use App\Console\Commands\NodeDefaultCommand;
use App\Console\Commands\NodeGrantCommand;
use App\Console\Commands\NodeListCommand;
use App\Console\Commands\NodeNewCommand;
use App\Console\Commands\NodeRemoveCommand;
use App\Console\Commands\NodeRevokeCommand;
use App\Console\Commands\NodeShowCommand;
use App\Console\Commands\NodeUpdateCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * All commands that implement the private callerRole() helper.
 *
 * @return list<class-string>
 */
function callerRoleCommands(): array
{
    return [
        NodeShowCommand::class,
        NodeListCommand::class,
        NodeUpdateCommand::class,
        NodeRevokeCommand::class,
        NodeGrantCommand::class,
        NodeDefaultCommand::class,
        NodeRemoveCommand::class,
        NodeNewCommand::class,
        GatewayAddCommand::class,
        DnsResolveTldCommand::class,
    ];
}

/**
 * Invoke the private callerRole() method on a command instance.
 */
function invokeCallerRole(object $command): string
{
    $reflection = new ReflectionMethod($command, 'callerRole');
    $reflection->setAccessible(true);

    return $reflection->invoke($command);
}

/**
 * Create a command instance with no constructor arguments.
 *
 * @param  class-string  $class
 */
function makeCommand(string $class): object
{
    return app($class);
}

describe('callerRole parity across all commands', function (): void {
    beforeEach(function (): void {
        DB::table('nodes')->truncate();
    });

    it('returns control when no local active node exists', function (): void {
        foreach (callerRoleCommands() as $commandClass) {
            $role = invokeCallerRole(makeCommand($commandClass));

            expect($role)
                ->toBe('control', "Expected {$commandClass} to return 'control' when no local node exists");
        }
    });

    it('returns control when local node has role control', function (): void {
        DB::table('nodes')->insert([
            'name' => 'local-control',
            'role' => 'control',
            'host' => '10.6.0.2',
            'ssh_user' => 'nckrtl',
            'orbit_path' => '/home/nckrtl/orbit',
            'status' => 'active',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (callerRoleCommands() as $commandClass) {
            $role = invokeCallerRole(makeCommand($commandClass));

            expect($role)
                ->toBe('control', "Expected {$commandClass} to return 'control' for control role");
        }
    });

    it('returns gateway when local node has role gateway', function (): void {
        DB::table('nodes')->insert([
            'name' => 'local-gateway',
            'role' => 'gateway',
            'host' => '10.6.0.1',
            'ssh_user' => 'nckrtl',
            'orbit_path' => '/home/nckrtl/orbit',
            'status' => 'active',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (callerRoleCommands() as $commandClass) {
            $role = invokeCallerRole(makeCommand($commandClass));

            expect($role)
                ->toBe('gateway', "Expected {$commandClass} to return 'gateway' for gateway role");
        }
    });

    it('returns app when local node has role app', function (): void {
        DB::table('nodes')->insert([
            'name' => 'local-app',
            'role' => 'app',
            'host' => '10.6.0.3',
            'ssh_user' => 'nckrtl',
            'orbit_path' => '/home/nckrtl/orbit',
            'status' => 'active',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (callerRoleCommands() as $commandClass) {
            $role = invokeCallerRole(makeCommand($commandClass));

            expect($role)
                ->toBe('app', "Expected {$commandClass} to return 'app' for app role");
        }
    });

    it('returns unknown when local node has unsupported role', function (): void {
        DB::table('nodes')->insert([
            'name' => 'local-bogus',
            'role' => 'bogus',
            'host' => '10.6.0.4',
            'ssh_user' => 'nckrtl',
            'orbit_path' => '/home/nckrtl/orbit',
            'status' => 'active',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (callerRoleCommands() as $commandClass) {
            $role = invokeCallerRole(makeCommand($commandClass));

            expect($role)
                ->toBe('unknown', "Expected {$commandClass} to return 'unknown' for unsupported role 'bogus'");
        }
    });

    it('returns control when local node is inactive', function (): void {
        DB::table('nodes')->insert([
            'name' => 'local-inactive',
            'role' => 'gateway',
            'host' => '10.6.0.5',
            'ssh_user' => 'nckrtl',
            'orbit_path' => '/home/nckrtl/orbit',
            'status' => 'inactive',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (callerRoleCommands() as $commandClass) {
            $role = invokeCallerRole(makeCommand($commandClass));

            expect($role)
                ->toBe('control', "Expected {$commandClass} to return 'control' for inactive local node");
        }
    });
});
