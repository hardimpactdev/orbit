<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

describe('node:new', function (): void {
    it('ships a local installer for control and gateway bootstrap hosts', function (): void {
        $installer = base_path('bin/install-orbit');

        expect($installer)->toBeFile()
            ->and(is_executable($installer))->toBeTrue();

        $syntax = Process::run('bash -n '.escapeshellarg($installer));

        expect($syntax->successful())->toBeTrue($syntax->errorOutput());

        $help = Process::run(escapeshellarg($installer).' --help');

        expect($help->successful())->toBeTrue($help->errorOutput())
            ->and($help->output())->toContain('--role=control|gateway|app')
            ->and($help->output())->toContain('--source-archive=PATH')
            ->and($help->output())->toContain('--verbose')
            ->and($help->output())->toContain('A new control node runs it')
            ->and($help->output())->toContain('First-gateway bootstrap may ship this same script');
    });

    it('renders installer failures with Orbit-style progress and stable error codes', function (): void {
        $installer = base_path('bin/install-orbit');
        $result = Process::run(escapeshellarg($installer).' --role=invalid --path=/tmp/orbit-test --bin=/tmp/orbit-test --no-sudo');

        expect($result->failed())->toBeTrue()
            ->and($result->output())->toContain('┌ Orbit install')
            ->and($result->output())->toContain('◉  Validate installer input')
            ->and($result->output())->not->toContain('+ ')
            ->and($result->errorOutput())->toContain('error [validation_failed]')
            ->and($result->errorOutput())->toContain('--role must be one of: control, gateway, app');
    });

    it('bootstraps the first gateway from an unconfigured control node', function (): void {
        Process::fake(['*' => Process::result(output: "Orbit 0.1.0\n")]);
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('node:new', [
            'name' => 'gateway-1',
            '--role' => 'gateway',
            '--host' => '192.0.2.10',
            '--ssh-user' => 'orbit',
            '--control-name' => 'mini',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['result']['action'])->toBe('created')
            ->and($payload['success']['data']['node'])->toMatchArray([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
                'tld' => null,
                'platform' => 'unknown',
                'addresses' => [
                    'wireguard' => '10.6.0.2',
                    'gateway_endpoint' => '192.0.2.10',
                ],
                'status' => 'active',
            ])
            ->and($payload['success']['data']['provisioning'])->toBe([
                'transport' => 'ssh',
                'host' => '192.0.2.10',
                'status' => 'complete',
            ])
            ->and($payload['success']['data']['local_control_node']['name'])->toBe('mini')
            ->and($payload['success']['data']['local_onboarding'])->toBe([
                'wireguard' => 'pending',
                'gateway_trust' => 'pending',
                'gateway_config' => 'stored',
                'gateway_api' => 'pending',
            ])
            ->and($payload)->not->toHaveKey('error');

        $gateway = DB::table('nodes')->where('name', 'gateway-1')->first();
        $control = DB::table('nodes')->where('name', 'mini')->first();

        expect($gateway)->not->toBeNull()
            ->and($gateway->role)->toBe('gateway')
            ->and($gateway->host)->toBe('192.0.2.10')
            ->and($gateway->wireguard_address)->toBe('10.6.0.2')
            ->and($gateway->gateway_endpoint)->toBe('192.0.2.10')
            ->and((bool) $gateway->is_local)->toBeFalse()
            ->and($control)->not->toBeNull()
            ->and($control->role)->toBe('control')
            ->and((bool) $control->is_local)->toBeTrue();

        Process::assertRan(fn ($process): bool => str_starts_with($process->command, 'tar '));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'scp ')
            && str_contains($process->command, 'install-orbit'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'ssh ')
            && str_contains($process->command, '--role=')
            && str_contains($process->command, 'gateway')
            && str_contains($process->command, '--source-archive='));
    });

    it('fails app-node creation before side effects when no gateway is configured', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('node:new', [
            'name' => 'app-1',
            '--role' => 'app',
            '--host' => '192.0.2.20',
            '--environment' => 'development',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway connection is required before creating app or control nodes.',
                    'meta' => [
                        'requested_role' => 'app',
                    ],
                ],
            ])
            ->and(DB::table('nodes')->count())->toBe(0);

        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('does not reprovision a gateway while gateway forwarding is unavailable', function (): void {
        DB::table('nodes')->insert([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'host' => '10.6.0.2',
            'ssh_user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'is_local' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Process::fake();
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('node:new', [
            'name' => 'gateway-2',
            '--role' => 'gateway',
            '--host' => '192.0.2.11',
            '--control-name' => 'mini',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway forwarding is required before gateway convergence can run.',
                    'meta' => [
                        'requested_role' => 'gateway',
                    ],
                ],
            ])
            ->and(DB::table('nodes')->where('name', 'gateway-2')->exists())->toBeFalse();

        Process::assertRanTimes(fn (): bool => true, 0);
    });
});
