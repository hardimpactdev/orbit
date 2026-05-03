<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

describe('node:new', function (): void {
    beforeEach(function (): void {
        $this->tempStorage = sys_get_temp_dir().'/orbit-node-new-test-'.uniqid();
        mkdir($this->tempStorage.'/app/orbit', 0777, true);
        app()->useStoragePath($this->tempStorage);

        $factory = new Factory;
        $tmpKey = tempnam(sys_get_temp_dir(), 'orbit-test-key-');
        $tmpCrt = tempnam(sys_get_temp_dir(), 'orbit-test-crt-');

        $factory->run(sprintf('openssl genrsa -out %s 2048', escapeshellarg($tmpKey)));
        $factory->run(implode(' ', [
            'openssl req -x509 -new -nodes',
            '-key '.escapeshellarg($tmpKey),
            '-sha256 -days 1',
            '-out '.escapeshellarg($tmpCrt),
            '-subj '.escapeshellarg('/CN=Test CA/O=Orbit'),
        ]));

        $this->mockCaCert = trim($factory->run(sprintf('cat %s', escapeshellarg($tmpCrt)))->output());

        @unlink($tmpKey);
        @unlink($tmpCrt);
    });

    afterEach(function (): void {
        if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
            File::deleteDirectory($this->tempStorage);
        }
    });

    it('ships a local installer for control and gateway bootstrap hosts', function (): void {
        $installer = base_path('bin/install-orbit');
        $contents = file_get_contents($installer);

        expect($installer)->toBeFile()
            ->and(is_executable($installer))->toBeTrue();

        $syntax = Process::run('bash -n '.escapeshellarg($installer));

        expect($syntax->successful())->toBeTrue($syntax->errorOutput());

        $help = Process::run(escapeshellarg($installer).' --help');

        expect($help->successful())->toBeTrue($help->errorOutput())
            ->and($help->output())->toContain('--role=control|gateway|app')
            ->and($help->output())->toContain('--php=8.5')
            ->and($help->output())->toContain('--source-archive=PATH')
            ->and($help->output())->toContain('--verbose')
            ->and($help->output())->toContain('A new control node runs it')
            ->and($help->output())->toContain('First-gateway bootstrap may ship this same script')
            ->and($contents)->toContain('packages.sury.org/php')
            ->and($contents)->toContain('sury-php.gpg')
            ->and($contents)->toContain('php${PHP_VERSION}-cli');
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

    it('bootstraps the first gateway from an unconfigured control node using a distinct bootstrap user', function (): void {
        $mockCaCert = $this->mockCaCert;

        Process::fake(function ($process) use ($mockCaCert) {
            if (str_contains($process->command, 'orbit:internal:bootstrap-gateway-local')) {
                return Process::result(output: $mockCaCert."\n");
            }

            return Process::result(output: '');
        });
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('node:new', [
            'name' => 'gateway-1',
            '--role' => 'gateway',
            '--host' => '192.0.2.10',
            '--ssh-user' => 'provisioner',
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
                'gateway_trust' => 'trusted',
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
            ->and($gateway->ssh_user)->toBe('provisioner')
            ->and($gateway->user)->toBe('orbit')
            ->and($gateway->orbit_path)->toBe('/home/orbit/orbit')
            ->and((bool) $gateway->is_local)->toBeFalse()
            ->and($control)->not->toBeNull()
            ->and($control->role)->toBe('control')
            ->and((bool) $control->is_local)->toBeTrue();

        $trustPath = storage_path('app/orbit/trust/gateway-1-ca.crt');
        expect(file_exists($trustPath))->toBeTrue()
            ->and(file_get_contents($trustPath))->toBe($mockCaCert);

        Process::assertRan(fn ($process): bool => str_starts_with($process->command, 'tar '));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'scp ')
            && str_contains($process->command, 'install-orbit'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'ssh ')
            && str_contains($process->command, 'useradd')
            && str_contains($process->command, 'usermod')
            && str_contains($process->command, 'sudoers.d/99-orbit')
            && str_contains($process->command, 'orbit'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'ssh ')
            && str_contains($process->command, '--role=')
            && str_contains($process->command, 'gateway')
            && str_contains($process->command, '--source-archive=')
            && str_contains($process->command, 'sudo su -'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'ssh ')
            && str_contains($process->command, 'orbit:internal:bootstrap-gateway-local'));
    });

    it('fails when remote bootstrap returns an invalid or empty CA certificate', function (): void {
        Process::fake(function ($process) {
            if (str_contains($process->command, 'orbit:internal:bootstrap-gateway-local')) {
                return Process::result(output: 'Orbit 0.1.0\n');
            }

            return Process::result(output: '');
        });
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('node:new', [
            'name' => 'gateway-invalid',
            '--role' => 'gateway',
            '--host' => '192.0.2.99',
            '--ssh-user' => 'provisioner',
            '--control-name' => 'mini',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'node.provisioning_incomplete',
                    'message' => "Gateway host '192.0.2.99' returned an invalid or empty CA certificate.",
                    'meta' => [
                        'host' => '192.0.2.99',
                        'step' => 'bootstrap_gateway_identity',
                        'error' => 'Remote bootstrap did not output a valid PEM certificate.',
                    ],
                ],
            ])
            ->and(DB::table('nodes')->count())->toBe(0);

        $trustPath = storage_path('app/orbit/trust/gateway-invalid-ca.crt');
        expect(file_exists($trustPath))->toBeFalse();
    });

    it('fails when remote bootstrap returns malformed PEM with valid delimiters', function (): void {
        Process::fake(function ($process) {
            if (str_contains($process->command, 'orbit:internal:bootstrap-gateway-local')) {
                return Process::result(output: "-----BEGIN CERTIFICATE-----\nnot-a-valid-cert\n-----END CERTIFICATE-----\n");
            }

            return Process::result(output: '');
        });
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('node:new', [
            'name' => 'gateway-malformed',
            '--role' => 'gateway',
            '--host' => '192.0.2.88',
            '--ssh-user' => 'provisioner',
            '--control-name' => 'mini',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'node.provisioning_incomplete',
                    'message' => "Gateway host '192.0.2.88' returned an unparsable CA certificate.",
                    'meta' => [
                        'host' => '192.0.2.88',
                        'step' => 'bootstrap_gateway_identity',
                        'error' => 'Remote bootstrap output is not a valid X.509 certificate.',
                    ],
                ],
            ])
            ->and(DB::table('nodes')->count())->toBe(0);

        $trustPath = storage_path('app/orbit/trust/gateway-malformed-ca.crt');
        expect(file_exists($trustPath))->toBeFalse();
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

    it('provisions a development app node from a gateway caller', function (): void {
        DB::table('nodes')->insert([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
            'tld' => null,
            'platform' => 'unknown',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
            'gateway_endpoint' => null,
            'ssh_user' => 'orbit',
            'user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Process::fake();
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('node:new', [
            'name' => 'app-dev-1',
            '--role' => 'app',
            '--host' => '192.0.2.20',
            '--environment' => 'development',
            '--tld' => 'test',
            '--ssh-user' => 'provisioner',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $node = DB::table('nodes')->where('name', 'app-dev-1')->first();

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['result']['action'])->toBe('created')
            ->and($payload['success']['data']['node'])->toMatchArray([
                'name' => 'app-dev-1',
                'role' => 'app',
                'environment' => 'development',
                'tld' => 'test',
                'status' => 'active',
            ])
            ->and($payload['success']['data']['node']['addresses']['wireguard'])->toBe('10.6.0.3')
            ->and($payload['success']['data']['development_tld'])->toMatchArray([
                'tld' => 'test',
                'gateway_dns' => [
                    'domain' => '*.test',
                    'target' => '10.6.0.3',
                    'status' => 'configured',
                ],
            ])
            ->and($node)->not->toBeNull()
            ->and($node->role)->toBe('app')
            ->and($node->environment)->toBe('development')
            ->and($node->tld)->toBe('test')
            ->and($node->host)->toBe('192.0.2.20')
            ->and($node->wireguard_address)->toBe('10.6.0.3')
            ->and($node->gateway_endpoint)->toBe('10.6.0.2')
            ->and($node->ssh_user)->toBe('provisioner')
            ->and($node->user)->toBe('orbit')
            ->and($node->orbit_path)->toBe('/home/orbit/orbit')
            ->and((bool) $node->is_local)->toBeFalse();

        Process::assertRan(fn ($process): bool => str_contains($process->command, '--role=')
            && str_contains($process->command, 'app')
            && str_contains($process->command, '--source-archive='));
    });

    it('provisions a production app node without a development tld from a gateway caller', function (): void {
        DB::table('nodes')->insert([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
            'tld' => null,
            'platform' => 'unknown',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
            'gateway_endpoint' => null,
            'ssh_user' => 'orbit',
            'user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Process::fake();
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('node:new', [
            'name' => 'app-prod-1',
            '--role' => 'app',
            '--host' => '192.0.2.21',
            '--environment' => 'production',
            '--ssh-user' => 'provisioner',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $node = DB::table('nodes')->where('name', 'app-prod-1')->first();

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node'])->toMatchArray([
                'name' => 'app-prod-1',
                'role' => 'app',
                'environment' => 'production',
                'tld' => null,
                'status' => 'active',
            ])
            ->and($payload['success']['data'])->not->toHaveKey('development_tld')
            ->and($node)->not->toBeNull()
            ->and($node->environment)->toBe('production')
            ->and($node->tld)->toBeNull();
    });

    it('requires a tld for development app nodes before side effects', function (): void {
        DB::table('nodes')->insert([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
            'tld' => null,
            'platform' => 'unknown',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
            'gateway_endpoint' => null,
            'ssh_user' => 'orbit',
            'user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Process::fake();
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('node:new', [
            'name' => 'app-dev-1',
            '--role' => 'app',
            '--host' => '192.0.2.20',
            '--environment' => 'development',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('tld')
            ->and(DB::table('nodes')->where('name', 'app-dev-1')->exists())->toBeFalse();

        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('forwards app-node creation from a configured control node to the gateway API', function (): void {
        DB::table('nodes')->insert([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'tld' => null,
            'platform' => 'unknown',
            'host' => '127.0.0.1',
            'wireguard_address' => '10.6.0.3',
            'gateway_endpoint' => '10.6.0.2',
            'ssh_user' => get_current_user(),
            'user' => get_current_user(),
            'orbit_path' => base_path(),
            'status' => 'active',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nodes')->insert([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
            'tld' => null,
            'platform' => 'unknown',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
            'gateway_endpoint' => null,
            'ssh_user' => 'orbit',
            'user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'is_local' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('local_gateway_settings')->insert([
            'gateway_url' => 'https://10.6.0.2',
            'gateway_wg_ip' => '10.6.0.2',
            'ca_sha256' => 'fake',
            'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
            'trusted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://10.6.0.2/api/nodes' => Http::response([
                'success' => [
                    'data' => [
                        'result' => ['action' => 'created'],
                        'node' => [
                            'name' => 'app-dev-1',
                            'role' => 'app',
                            'environment' => 'development',
                            'tld' => 'test',
                            'platform' => 'unknown',
                            'addresses' => [
                                'wireguard' => '10.6.0.4',
                                'gateway_endpoint' => '10.6.0.2',
                            ],
                            'status' => 'active',
                        ],
                        'provisioning' => [
                            'transport' => 'ssh',
                            'host' => '192.0.2.20',
                            'status' => 'complete',
                        ],
                    ],
                ],
            ]),
        ]);

        Process::fake();
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('node:new', [
            'name' => 'app-dev-1',
            '--role' => 'app',
            '--host' => '192.0.2.20',
            '--environment' => 'development',
            '--tld' => 'test',
            '--ssh-user' => 'provisioner',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['name'])->toBe('app-dev-1')
            ->and(DB::table('nodes')->where('name', 'app-dev-1')->exists())->toBeFalse();

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://10.6.0.2/api/nodes'
            && $request['name'] === 'app-dev-1'
            && $request['role'] === 'app'
            && $request['host'] === '192.0.2.20'
            && $request['environment'] === 'development'
            && $request['tld'] === 'test'
            && $request['ssh_user'] === 'provisioner');

        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('forwards app-node creation when gateway add only stored local gateway settings', function (): void {
        DB::table('local_gateway_settings')->insert([
            'gateway_url' => 'https://10.6.0.2',
            'gateway_wg_ip' => '10.6.0.2',
            'ca_sha256' => 'fake',
            'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
            'trusted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://10.6.0.2/api/nodes' => Http::response([
                'success' => [
                    'data' => [
                        'result' => ['action' => 'created'],
                        'node' => [
                            'name' => 'app-dev-1',
                            'role' => 'app',
                            'environment' => 'development',
                            'tld' => 'test',
                            'platform' => 'unknown',
                            'addresses' => [
                                'wireguard' => '10.6.0.3',
                            ],
                            'status' => 'active',
                        ],
                        'provisioning' => [
                            'transport' => 'ssh',
                            'host' => '192.0.2.20',
                            'status' => 'complete',
                        ],
                    ],
                ],
            ]),
        ]);

        Process::fake();
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('node:new', [
            'name' => 'app-dev-1',
            '--role' => 'app',
            '--host' => '192.0.2.20',
            '--environment' => 'development',
            '--tld' => 'test',
            '--ssh-user' => 'provisioner',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['name'])->toBe('app-dev-1')
            ->and(DB::table('nodes')->where('name', 'app-dev-1')->exists())->toBeFalse();

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://10.6.0.2/api/nodes'
            && $request['name'] === 'app-dev-1'
            && $request['role'] === 'app'
            && $request['host'] === '192.0.2.20'
            && $request['environment'] === 'development'
            && $request['tld'] === 'test'
            && $request['ssh_user'] === 'provisioner');

        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('does not reprovision a gateway while gateway forwarding is unavailable', function (): void {
        DB::table('nodes')->insert([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'host' => '10.6.0.2',
            'ssh_user' => 'orbit',
            'user' => 'orbit',
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
