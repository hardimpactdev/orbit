<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

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
});
