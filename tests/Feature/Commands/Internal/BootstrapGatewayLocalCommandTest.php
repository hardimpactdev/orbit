<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\WireGuardPeer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

describe('orbit:internal:bootstrap-gateway-local', function (): void {
    beforeEach(function (): void {
        $this->tempStorage = sys_get_temp_dir().'/orbit-ca-test-'.uniqid();
        mkdir($this->tempStorage.'/app/orbit', 0777, true);
        app()->useStoragePath($this->tempStorage);
    });

    afterEach(function (): void {
        if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
            File::deleteDirectory($this->tempStorage);
        }
    });

    it('creates a local gateway node record and generates the root CA', function (): void {
        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and(Node::query()->where('name', 'gateway-1')->exists())->toBeTrue()
            ->and(Node::query()->where('is_local', true)->value('role'))->toBe('gateway')
            ->and($output)->toContain('-----BEGIN CERTIFICATE-----')
            ->and($output)->toContain('-----END CERTIFICATE-----');
    });

    it('is idempotent when the gateway node and CA already exist', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
        ]);

        $firstOutput = Artisan::output();

        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
        ]);

        $secondOutput = Artisan::output();

        expect($firstOutput)->toBe($secondOutput)
            ->and(Node::query()->where('name', 'gateway-1')->count())->toBe(1);
    });

    it('demotes any existing local node before creating the gateway record', function (): void {
        Node::query()->create([
            'name' => 'old-control',
            'role' => 'control',
            'host' => '127.0.0.1',
            'ssh_user' => get_current_user(),
            'orbit_path' => base_path(),
            'status' => 'active',
            'is_local' => true,
        ]);

        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
        ]);

        expect((bool) Node::query()->where('name', 'old-control')->value('is_local'))->toBeFalse()
            ->and((bool) Node::query()->where('name', 'gateway-1')->value('is_local'))->toBeTrue();
    });

    it('persists wireguard peers and configures the gateway interface idempotently', function (): void {
        $writtenConfig = null;
        $caDir = storage_path('app/orbit/ca');

        File::ensureDirectoryExists($caDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");

        Process::fake(function ($process) use (&$writtenConfig) {
            if (str_contains($process->command, 'tee /etc/wireguard/wg-orbit.conf')) {
                $writtenConfig = (string) $process->input;
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        $identity = [
            'gateway' => [
                'public_key' => 'gateway-public-v1',
                'private_key' => 'gateway-private-v1',
            ],
            'control' => [
                'name' => 'mini',
                'wireguard_address' => '10.6.0.3',
                'public_key' => 'control-public-v1',
                'private_key' => 'control-private-v1',
            ],
        ];

        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--identity-json' => json_encode($identity, JSON_THROW_ON_ERROR),
        ]);

        $gateway = Node::query()->where('name', 'gateway-1')->first();
        $control = Node::query()->where('name', 'mini')->first();
        $gatewayPeer = WireGuardPeer::query()->where('node_id', $gateway?->id)->first();
        $controlPeer = WireGuardPeer::query()->where('node_id', $control?->id)->first();

        expect($exitCode)->toBe(0)
            ->and($gateway)->toBeInstanceOf(Node::class)
            ->and($control)->toBeInstanceOf(Node::class)
            ->and($control->role)->toBe('control')
            ->and($control->wireguard_address)->toBe('10.6.0.3')
            ->and($gatewayPeer)->toBeInstanceOf(WireGuardPeer::class)
            ->and($gatewayPeer->public_key)->toBe('gateway-public-v1')
            ->and($gatewayPeer->private_key)->toBe('gateway-private-v1')
            ->and($gatewayPeer->allowed_ips)->toBe('10.6.0.2/32')
            ->and($controlPeer)->toBeInstanceOf(WireGuardPeer::class)
            ->and($controlPeer->public_key)->toBe('control-public-v1')
            ->and($controlPeer->private_key)->toBe('control-private-v1')
            ->and($controlPeer->allowed_ips)->toBe('10.6.0.3/32')
            ->and($writtenConfig)->toContain('PrivateKey = gateway-private-v1')
            ->and($writtenConfig)->toContain('Address = 10.6.0.2/24')
            ->and($writtenConfig)->toContain('ListenPort = 51820')
            ->and($writtenConfig)->toContain('PublicKey = control-public-v1')
            ->and($writtenConfig)->toContain('AllowedIPs = 10.6.0.3/32');

        Process::assertRan(fn ($process): bool => str_contains($process->command, 'sudo mkdir -p /etc/wireguard'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'sudo tee /etc/wireguard/wg-orbit.conf'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'sudo wg-quick up wg-orbit'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'sudo systemctl enable wg-quick@wg-orbit'));

        $replacementIdentity = [
            'gateway' => [
                'public_key' => 'gateway-public-v2',
                'private_key' => 'gateway-private-v2',
            ],
            'control' => [
                'name' => 'mini',
                'wireguard_address' => '10.6.0.3',
                'public_key' => 'control-public-v2',
                'private_key' => 'control-private-v2',
            ],
        ];

        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--identity-json' => json_encode($replacementIdentity, JSON_THROW_ON_ERROR),
        ]);

        expect(Node::query()->whereIn('name', ['gateway-1', 'mini'])->count())->toBe(2)
            ->and(WireGuardPeer::query()->count())->toBe(2)
            ->and($gatewayPeer->fresh()->public_key)->toBe('gateway-public-v1')
            ->and($gatewayPeer->fresh()->private_key)->toBe('gateway-private-v1')
            ->and($controlPeer->fresh()->public_key)->toBe('control-public-v1')
            ->and($controlPeer->fresh()->private_key)->toBe('control-private-v1');
    });
});
