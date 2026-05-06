<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\CreateNodeRequest;
use App\Models\WireGuardPeer;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use App\Services\Trust\TrustStoreInstallReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(fn (): null => MockClient::destroyGlobal());
afterEach(fn (): null => MockClient::destroyGlobal());

function nodeNewExpectedLocalPlatform(): string
{
    return match (PHP_OS_FAMILY) {
        'Darwin' => 'macos_15-4',
        'Linux' => 'ubuntu_24-04',
        default => 'unknown',
    };
}

/**
 * @param  array<string, mixed>|string  $body
 */
function fakeNodeCreateGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        CreateNodeRequest::class => MockResponse::make($body, $status),
    ]);
}

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

        $this->fakeInstaller = new class implements TrustStoreInstaller
        {
            public bool $isTrusted = false;

            public bool $throwCommandFailed = false;

            /** @var list<array{path: string, label: string}> */
            public array $trustCalls = [];

            public function isCaTrusted(string $rootCaPath, string $label): bool
            {
                return $this->isTrusted;
            }

            public function trustCa(string $rootCaPath, string $label, ?Closure $log = null): void
            {
                $this->trustCalls[] = ['path' => $rootCaPath, 'label' => $label];

                if ($this->throwCommandFailed) {
                    throw new TrustStoreInstallException(
                        'Command failed',
                        TrustStoreInstallReason::CommandFailed,
                    );
                }

                $this->isTrusted = true;
            }
        };

        app()->instance(TrustStoreInstaller::class, $this->fakeInstaller);

        $this->fakeGatewayApiVerification = function (int $status = 200, ?array $payload = null): void {
            Http::fake([
                'https://10.6.0.2/api/me' => Http::response($payload ?? [
                    'success' => [
                        'data' => [
                            'self' => [
                                'name' => 'mini',
                                'role' => 'control',
                                'status' => 'active',
                                'platform' => 'unknown',
                                'addresses' => ['wireguard' => '10.6.0.3'],
                            ],
                            'gateway' => [
                                'name' => 'gateway-1',
                                'role' => 'gateway',
                                'status' => 'active',
                                'platform' => 'unknown',
                                'addresses' => ['wireguard' => '10.6.0.2'],
                            ],
                        ],
                    ],
                ], $status),
            ]);
        };

        $this->fakeFirstGatewayProcesses = function (
            string $bootstrapOutput,
            ?string &$bootstrapInput = null,
            string $gatewayPlatformOutput = 'ubuntu_24-04',
            int $gatewayPlatformExitCode = 0,
        ): void {
            $privateKeys = ['gateway-private-key', 'control-private-key'];
            $publicKeys = ['gateway-public-key', 'control-public-key'];

            Process::fake(function ($process) use ($bootstrapOutput, &$bootstrapInput, &$privateKeys, &$publicKeys, $gatewayPlatformOutput, $gatewayPlatformExitCode) {
                if ($process->command === 'wg genkey') {
                    return Process::result(output: array_shift($privateKeys)."\n");
                }

                if ($process->command === 'wg pubkey') {
                    return Process::result(output: array_shift($publicKeys)."\n");
                }

                if (str_contains($process->command, 'orbit:internal:bootstrap-gateway-local')) {
                    $bootstrapInput = (string) $process->input;

                    return Process::result(output: $bootstrapOutput."\n");
                }

                if (str_contains($process->command, 'orbit:internal:detect-platform')) {
                    return Process::result(
                        output: $gatewayPlatformOutput === '' ? '' : $gatewayPlatformOutput."\n",
                        errorOutput: $gatewayPlatformExitCode === 0 ? '' : 'platform unavailable',
                        exitCode: $gatewayPlatformExitCode,
                    );
                }

                if ($process->command === 'sw_vers -productVersion') {
                    return Process::result(output: "15.4\n");
                }

                if ($process->command === 'cat /etc/os-release') {
                    return Process::result(output: "ID=ubuntu\nVERSION_ID=\"24.04\"\n");
                }

                return Process::result(output: '');
            });
            Process::preventStrayProcesses();
        };
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
            ->and($contents)->toContain('php${PHP_VERSION}-cli')
            ->and($contents)->toContain('php${PHP_VERSION}-fpm')
            ->and($contents)->toContain('wireguard-tools')
            ->and($contents)->toContain('caddy');
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
        $bootstrapInput = null;

        ($this->fakeFirstGatewayProcesses)($mockCaCert, $bootstrapInput);
        ($this->fakeGatewayApiVerification)();

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
                'platform' => 'ubuntu_24-04',
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
            ->and($payload['success']['data']['local_control_node'])->toMatchArray([
                'name' => 'mini',
                'role' => 'control',
                'environment' => null,
                'tld' => null,
                'platform' => nodeNewExpectedLocalPlatform(),
                'addresses' => [
                    'wireguard' => '10.6.0.3',
                ],
                'status' => 'active',
            ])
            ->and($payload['success']['data']['local_onboarding'])->toBe([
                'wireguard' => 'installed',
                'gateway_trust' => 'trusted',
                'gateway_config' => 'stored',
                'gateway_api' => 'verified',
            ])
            ->and($payload['success']['data']['gateway_trust'])->toMatchArray([
                'gateway_url' => 'https://192.0.2.10',
                'trusted' => true,
                'status' => 'trusted',
                'ca_sha256' => hash('sha256', $mockCaCert),
            ])
            ->and($payload['success']['data']['next_steps'])->toBe([])
            ->and(json_encode($payload['success']['data'], JSON_THROW_ON_ERROR))->not->toContain('ssh_user')
            ->and(json_encode($payload['success']['data'], JSON_THROW_ON_ERROR))->not->toContain('gateway:add')
            ->and($payload)->not->toHaveKey('error');

        $gateway = DB::table('nodes')->where('name', 'gateway-1')->first();
        $control = DB::table('nodes')->where('name', 'mini')->first();

        expect($gateway)->not->toBeNull()
            ->and($gateway->role)->toBe('gateway')
            ->and($gateway->platform)->toBe('ubuntu_24-04')
            ->and($gateway->host)->toBe('192.0.2.10')
            ->and($gateway->wireguard_address)->toBe('10.6.0.2')
            ->and($gateway->gateway_endpoint)->toBe('192.0.2.10')
            ->and($gateway->ssh_user)->toBe('provisioner')
            ->and($gateway->user)->toBe('orbit')
            ->and($gateway->orbit_path)->toBe('/home/orbit/orbit')
            ->and((bool) $gateway->is_local)->toBeFalse()
            ->and($control)->not->toBeNull()
            ->and($control->role)->toBe('control')
            ->and($control->platform)->toBe(nodeNewExpectedLocalPlatform())
            ->and((bool) $control->is_local)->toBeTrue();

        $controlPeer = WireGuardPeer::query()->where('node_id', $control->id)->first();

        expect(WireGuardPeer::query()->where('node_id', $gateway->id)->exists())->toBeFalse()
            ->and($controlPeer)->toBeInstanceOf(WireGuardPeer::class)
            ->and($controlPeer->public_key)->toBe('control-public-key')
            ->and($controlPeer->private_key)->toBe('control-private-key')
            ->and($controlPeer->allowed_ips)->toBe('10.6.0.3/32');

        $identity = json_decode((string) $bootstrapInput, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($identity)->toMatchArray([
            'gateway' => [
                'public_key' => 'gateway-public-key',
                'private_key' => 'gateway-private-key',
            ],
            'control' => [
                'name' => 'mini',
                'wireguard_address' => '10.6.0.3',
                'public_key' => 'control-public-key',
                'private_key' => 'control-private-key',
            ],
        ]);

        $trustPath = storage_path('app/orbit/trust/gateway-1-ca.crt');
        expect(file_exists($trustPath))->toBeTrue()
            ->and(file_get_contents($trustPath))->toBe($mockCaCert);

        expect($this->fakeInstaller->trustCalls)->toBe([
            ['path' => $trustPath, 'label' => 'orbit'],
        ]);

        $settings = DB::table('local_gateway_settings')->first();

        expect($settings)->not->toBeNull()
            ->and($settings->gateway_url)->toBe('https://192.0.2.10')
            ->and($settings->gateway_wg_ip)->toBe('10.6.0.2')
            ->and($settings->ca_sha256)->toBe(hash('sha256', $mockCaCert))
            ->and($settings->ca_pem_path)->toBe($trustPath)
            ->and($settings->trusted_at)->not->toBeNull();

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
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'orbit:internal:bootstrap-gateway-local')
            && str_contains($process->command, '--identity-json=-')
            && ! str_contains($process->command, 'gateway-private-key')
            && ! str_contains($process->command, 'control-private-key'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'ssh ')
            && str_contains($process->command, 'orbit:internal:detect-platform --update-local-node'));

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://10.6.0.2/api/me');
    });

    it('converges an already bootstrapped first gateway without duplicating trust install', function (): void {
        $mockCaCert = $this->mockCaCert;

        ($this->fakeFirstGatewayProcesses)($mockCaCert);
        ($this->fakeGatewayApiVerification)();

        Artisan::call('node:new', [
            'name' => 'gateway-1',
            '--role' => 'gateway',
            '--host' => '192.0.2.10',
            '--ssh-user' => 'provisioner',
            '--control-name' => 'mini',
            '--json' => true,
        ]);

        $trustPath = storage_path('app/orbit/trust/gateway-1-ca.crt');
        $firstPem = file_get_contents($trustPath);

        Process::fake(fn ($process) => str_contains((string) $process->command, 'ssh-keygen -y')
            ? Process::result(output: "ssh-ed25519 AAAATEST gateway\n")
            : Process::result());
        Process::preventStrayProcesses();
        ($this->fakeGatewayApiVerification)();

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
            ->and($payload['success']['data']['result']['action'])->toBe('converged')
            ->and($payload['success']['data']['node']['platform'])->toBe('ubuntu_24-04')
            ->and($payload['success']['data']['local_control_node']['platform'])->toBe(nodeNewExpectedLocalPlatform())
            ->and($payload['success']['data']['provisioning'])->toBe([
                'transport' => 'none',
                'host' => '192.0.2.10',
                'status' => 'already_provisioned',
            ])
            ->and($payload['success']['data']['local_onboarding'])->toBe([
                'wireguard' => 'already_installed',
                'gateway_trust' => 'already_trusted',
                'gateway_config' => 'already_stored',
                'gateway_api' => 'verified',
            ])
            ->and($payload['success']['data']['gateway_trust']['status'])->toBe('already_trusted')
            ->and($payload['success']['data']['gateway_trust']['ca_sha256'])->toBe(hash('sha256', $mockCaCert))
            ->and($payload['success']['data']['next_steps'])->toBe([])
            ->and(json_encode($payload['success']['data'], JSON_THROW_ON_ERROR))->not->toContain('ssh_user')
            ->and(json_encode($payload['success']['data'], JSON_THROW_ON_ERROR))->not->toContain('gateway:add')
            ->and($this->fakeInstaller->trustCalls)->toHaveCount(1)
            ->and(file_get_contents($trustPath))->toBe($firstPem)
            ->and(DB::table('nodes')->count())->toBe(2)
            ->and(DB::table('nodes')->where('name', 'gateway-1')->value('platform'))->toBe('ubuntu_24-04')
            ->and(DB::table('nodes')->where('name', 'mini')->value('platform'))->toBe(nodeNewExpectedLocalPlatform());
    });

    it('shows first-gateway bootstrap platform fields through node:show json', function (): void {
        ($this->fakeFirstGatewayProcesses)($this->mockCaCert);
        ($this->fakeGatewayApiVerification)();

        Artisan::call('node:new', [
            'name' => 'gateway-1',
            '--role' => 'gateway',
            '--host' => '192.0.2.10',
            '--ssh-user' => 'provisioner',
            '--control-name' => 'mini',
            '--json' => true,
        ]);

        DB::table('nodes')->where('name', 'mini')->update(['is_local' => false]);
        DB::table('nodes')->where('name', 'gateway-1')->update(['is_local' => true]);

        $gatewayExitCode = Artisan::call('node:show', ['name' => 'gateway-1', '--json' => true]);
        $gatewayPayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $controlExitCode = Artisan::call('node:show', ['name' => 'mini', '--json' => true]);
        $controlPayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($gatewayExitCode)->toBe(0)
            ->and($gatewayPayload['success']['data']['node']['platform'])->toBe('ubuntu_24-04')
            ->and($controlExitCode)->toBe(0)
            ->and($controlPayload['success']['data']['node']['platform'])->toBe(nodeNewExpectedLocalPlatform());
    });

    it('fails before local onboarding persistence when platform detection fails', function (): void {
        ($this->fakeFirstGatewayProcesses)(
            bootstrapOutput: $this->mockCaCert,
            gatewayPlatformOutput: '',
            gatewayPlatformExitCode: 1,
        );

        $exitCode = Artisan::call('node:new', [
            'name' => 'gateway-platform-fail',
            '--role' => 'gateway',
            '--host' => '192.0.2.15',
            '--ssh-user' => 'provisioner',
            '--control-name' => 'mini',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'node.provisioning_incomplete',
                    'message' => "Gateway host '192.0.2.15' could not detect its platform.",
                    'meta' => [
                        'host' => '192.0.2.15',
                        'step' => 'platform_detection',
                        'error' => 'platform unavailable',
                    ],
                ],
            ])
            ->and(DB::table('nodes')->count())->toBe(0);
    });

    it('renders platform detection failure in human output', function (): void {
        ($this->fakeFirstGatewayProcesses)(
            bootstrapOutput: $this->mockCaCert,
            gatewayPlatformOutput: '',
            gatewayPlatformExitCode: 1,
        );

        $exitCode = Artisan::call('node:new', [
            'name' => 'gateway-platform-fail',
            '--role' => 'gateway',
            '--host' => '192.0.2.15',
            '--ssh-user' => 'provisioner',
            '--control-name' => 'mini',
        ]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain("Gateway host '192.0.2.15' could not detect its platform.")
            ->and(DB::table('nodes')->count())->toBe(0);
    });

    it('fails when first gateway API verification fails after local onboarding state is stored', function (): void {
        ($this->fakeFirstGatewayProcesses)($this->mockCaCert);
        ($this->fakeGatewayApiVerification)(500, ['error' => ['message' => 'down']]);

        $exitCode = Artisan::call('node:new', [
            'name' => 'gateway-1',
            '--role' => 'gateway',
            '--host' => '192.0.2.10',
            '--ssh-user' => 'provisioner',
            '--control-name' => 'mini',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'node.gateway_api_error',
                    'message' => 'Gateway at 10.6.0.2 returned HTTP 500 for /api/me.',
                    'meta' => [
                        'gateway_ip' => '10.6.0.2',
                        'status' => 500,
                        'endpoint' => '/api/me',
                    ],
                ],
            ])
            ->and(DB::table('nodes')->where('name', 'gateway-1')->exists())->toBeTrue()
            ->and(DB::table('local_gateway_settings')->where('gateway_wg_ip', '10.6.0.2')->exists())->toBeTrue();
    });

    it('fails when local gateway CA trust installation fails during first gateway bootstrap', function (): void {
        $this->fakeInstaller->throwCommandFailed = true;

        ($this->fakeFirstGatewayProcesses)($this->mockCaCert);

        $exitCode = Artisan::call('node:new', [
            'name' => 'gateway-trust-fail',
            '--role' => 'gateway',
            '--host' => '192.0.2.77',
            '--ssh-user' => 'provisioner',
            '--control-name' => 'mini',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'node.provisioning_incomplete',
                    'message' => 'Failed to install the gateway CA into the local trust store.',
                    'meta' => [
                        'host' => '192.0.2.77',
                        'step' => 'gateway_ca_trust',
                        'error' => 'Trust store installation failed.',
                    ],
                ],
            ])
            ->and(DB::table('nodes')->count())->toBe(0);
    });

    it('fails when remote bootstrap returns an invalid or empty CA certificate', function (): void {
        ($this->fakeFirstGatewayProcesses)('Orbit 0.1.0');

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
        ($this->fakeFirstGatewayProcesses)("-----BEGIN CERTIFICATE-----\nnot-a-valid-cert\n-----END CERTIFICATE-----");

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
        Process::fake(fn ($process) => str_contains((string) $process->command, 'ssh-keygen -y')
            ? Process::result(output: "ssh-ed25519 AAAATEST gateway\n")
            : Process::result());
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

        Process::fake(fn ($process) => str_contains((string) $process->command, 'ssh-keygen -y')
            ? Process::result(output: "ssh-ed25519 AAAATEST gateway\n")
            : Process::result());
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
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'authorized_keys')
            && str_contains($process->command, 'ssh-ed25519 AAAATEST gateway'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'usermod -p')
            && str_contains($process->command, 'orbit'));
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

        Process::fake(fn ($process) => str_contains((string) $process->command, 'ssh-keygen -y')
            ? Process::result(output: "ssh-ed25519 AAAATEST gateway\n")
            : Process::result());
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

        $mock = fakeNodeCreateGateway([
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

        $mock->assertSent(fn (CreateNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes'
            && $request->body()->all() === [
                'name' => 'app-dev-1',
                'role' => 'app',
                'host' => '192.0.2.20',
                'environment' => 'development',
                'tld' => 'test',
                'ssh_user' => 'provisioner',
            ]);

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

        $mock = fakeNodeCreateGateway([
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

        $mock->assertSent(fn (CreateNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes'
            && $request->body()->all() === [
                'name' => 'app-dev-1',
                'role' => 'app',
                'host' => '192.0.2.20',
                'environment' => 'development',
                'tld' => 'test',
                'ssh_user' => 'provisioner',
            ]);

        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('forwards control-node enrollment when gateway add only stored local gateway settings', function (): void {
        DB::table('local_gateway_settings')->insert([
            'gateway_url' => 'https://10.6.0.2',
            'gateway_wg_ip' => '10.6.0.2',
            'ca_sha256' => 'fake',
            'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
            'trusted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mock = fakeNodeCreateGateway([
            'success' => [
                'data' => [
                    'result' => ['action' => 'enrolled'],
                    'node' => [
                        'name' => 'control-2',
                        'role' => 'control',
                        'environment' => null,
                        'tld' => null,
                        'platform' => 'unknown',
                        'addresses' => [
                            'wireguard' => '10.6.0.4',
                        ],
                        'status' => 'active',
                    ],
                    'provisioning' => [
                        'transport' => 'wireguard',
                        'host' => null,
                        'status' => 'enrolled',
                    ],
                    'wireguard' => [
                        'config' => "[Interface]\nPrivateKey = control-private-key\n",
                    ],
                    'next_steps' => [
                        'Install the returned WireGuard configuration on control-2, then run `orbit gateway:add` from that control node.',
                    ],
                ],
            ],
        ]);

        Process::fake();
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('node:new', [
            'name' => 'control-2',
            '--role' => 'control',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['name'])->toBe('control-2')
            ->and($payload['success']['data']['wireguard']['config'])->toContain('[Interface]')
            ->and(DB::table('nodes')->where('name', 'control-2')->exists())->toBeFalse();

        $mock->assertSent(fn (CreateNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes'
            && $request->body()->all() === [
                'name' => 'control-2',
                'role' => 'control',
                'host' => null,
                'environment' => null,
                'tld' => null,
                'ssh_user' => null,
            ]);

        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('enrolls a control node locally on a gateway without SSH side effects', function (): void {
        DB::table('nodes')->insert([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
            'ssh_user' => 'orbit',
            'user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        WireGuardPeer::query()->create([
            'node_id' => DB::table('nodes')->where('name', 'gateway-1')->value('id'),
            'public_key' => 'gateway-public-key',
            'private_key' => 'gateway-private-key',
            'allowed_ips' => '10.6.0.2/32',
        ]);

        Process::fake(function ($process) {
            if ($process->command === 'wg genkey') {
                return Process::result(output: "control-private-key\n");
            }

            if ($process->command === 'wg pubkey') {
                return Process::result(output: "control-public-key\n");
            }

            return Process::result(output: '');
        });
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('node:new', [
            'name' => 'control-2',
            '--role' => 'control',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $control = DB::table('nodes')->where('name', 'control-2')->first();

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['result']['action'])->toBe('enrolled')
            ->and($payload['success']['data']['node'])->toMatchArray([
                'name' => 'control-2',
                'role' => 'control',
                'environment' => null,
                'tld' => null,
                'platform' => 'unknown',
                'addresses' => [
                    'wireguard' => '10.6.0.3',
                ],
                'status' => 'active',
            ])
            ->and($payload['success']['data']['provisioning'])->toBe([
                'transport' => 'wireguard',
                'host' => null,
                'status' => 'enrolled',
            ])
            ->and($payload['success']['data']['wireguard']['config'])->toContain('PrivateKey = control-private-key')
            ->and($payload['success']['data']['wireguard']['config'])->toContain('PublicKey = gateway-public-key')
            ->and($payload['success']['data']['next_steps'])->toBe([
                'Install the returned WireGuard configuration on control-2, then run `orbit gateway:add` from that control node.',
            ])
            ->and($control)->not->toBeNull()
            ->and($control->role)->toBe('control')
            ->and($control->wireguard_address)->toBe('10.6.0.3')
            ->and((bool) $control->is_local)->toBeFalse();

        $controlPeer = WireGuardPeer::query()->where('node_id', $control->id)->first();

        expect($controlPeer)->toBeInstanceOf(WireGuardPeer::class)
            ->and($controlPeer->public_key)->toBe('control-public-key')
            ->and($controlPeer->private_key)->toBe('control-private-key')
            ->and($controlPeer->allowed_ips)->toBe('10.6.0.3/32');

        Process::assertRanTimes(fn ($process): bool => str_contains($process->command, 'ssh '), 0);
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
