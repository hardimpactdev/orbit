<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

it('converges the existing vpn role runtime through its installer', function (): void {
    $environmentPath = sys_get_temp_dir().'/orbit-vpn-dns-runtime-'.uniqid();
    File::ensureDirectoryExists($environmentPath);
    File::put("{$environmentPath}/.env", "APP_NAME=Orbit\n");
    $originalEnvironmentPath = app()->environmentPath();
    app()->useEnvironmentPath($environmentPath);

    $installer = Mockery::mock(VpnDnsSwarmInstaller::class);
    $installer->shouldReceive('install')->once();

    app()->instance(VpnDnsSwarmInstaller::class, $installer);
    config()->set('services.wg_easy.username', 'orbit-test');
    config()->set('services.wg_easy.password', null);

    $node = Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'vpn',
        'status' => 'active',
        'settings' => [
            'public_endpoint' => '192.0.2.10',
            'wireguard_cidr' => '10.44.0.0/24',
            'wireguard_port' => 51_821,
            'dns_ip' => '10.44.0.1',
        ],
    ]);

    $exitCode = null;

    try {
        expect(Artisan::all())->toHaveKey('orbit:internal:converge-vpn-dns-runtime');
        expect(function () use (&$exitCode): void {
            $exitCode = Artisan::call('orbit:internal:converge-vpn-dns-runtime', ['node' => 'gateway']);
        })
            ->not
            ->toThrow(RuntimeException::class);

        expect($exitCode)
            ->toBe(0)
            ->and(File::get(app()->environmentFilePath()))
            ->toContain('WG_EASY_PASSWORD=')
            ->and(config('services.wg_easy.password'))
            ->toBeString()
            ->not->toBeEmpty();
    } finally {
        app()->useEnvironmentPath($originalEnvironmentPath);
        File::deleteDirectory($environmentPath);
    }
});
