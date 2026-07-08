<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function caRootControllerTestRootPem(): string
{
    $fixtureDir = sys_get_temp_dir().'/orbit-ca-root-controller-fixture-'.getmypid();
    File::ensureDirectoryExists($fixtureDir);

    $rootKey = "{$fixtureDir}/root.key";
    $rootCrt = "{$fixtureDir}/root.crt";

    if (! File::exists($rootKey) || ! File::exists($rootCrt)) {
        $factory = new Factory;
        $factory->run(sprintf('openssl genrsa -out %s 2048', escapeshellarg($rootKey)))->throw();
        $factory->run(implode(' ', [
            'openssl req -x509 -new -nodes',
            '-key '.escapeshellarg($rootKey),
            '-sha256 -days 3650',
            '-out '.escapeshellarg($rootCrt),
            '-subj '.escapeshellarg('/CN=Orbit Test Root CA/O=Orbit Tests'),
        ]))->throw();
    }

    return File::get($rootCrt);
}

describe('GET /api/ca/root', function (): void {
    beforeEach(function (): void {
        $this->tempStorage = sys_get_temp_dir().'/orbit-api-ca-test-'.uniqid();
        app()->useStoragePath($this->tempStorage);
        $this->tempConfigRoot = "{$this->tempStorage}/config";
        File::ensureDirectoryExists("{$this->tempConfigRoot}/ca");
        config()->set('orbit.paths.config_root', $this->tempConfigRoot);
    });

    afterEach(function (): void {
        if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
            File::deleteDirectory($this->tempStorage);
        }
    });

    it('returns success envelope with root_ca PEM', function (): void {
        Node::factory()
            ->gateway()
            ->create([
                'name' => 'gateway-1',
                'host' => '10.6.0.2',
                'wireguard_address' => '10.6.0.2',
                'orbit_path' => '/home/orbit/orbit',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
            ]);

        $pem = caRootControllerTestRootPem();
        $caDir = "{$this->tempConfigRoot}/ca";
        file_put_contents("{$caDir}/root.crt", $pem);
        file_put_contents("{$caDir}/root.key", 'dummy-key');

        $response = $this->getJson('/api/ca/root');

        $response
            ->assertOk()
            ->assertExactJson([
                'success' => [
                    'data' => [
                        'root_ca' => $pem,
                    ],
                ],
            ]);
    });

    it('returns error envelope when CA is not bootstrapped', function (): void {
        Node::factory()
            ->gateway()
            ->create([
                'name' => 'gateway-1',
                'host' => '10.6.0.2',
                'wireguard_address' => '10.6.0.2',
                'orbit_path' => '/home/orbit/orbit',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
            ]);

        $response = $this->getJson('/api/ca/root');

        $response
            ->assertStatus(503)
            ->assertJson([
                'error' => [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway root CA is not available.',
                    'meta' => [
                        'reason' => 'ca_not_bootstrapped',
                    ],
                ],
            ]);
    });

    it('returns error envelope when root.crt is not a parseable certificate', function (): void {
        Node::factory()
            ->gateway()
            ->create([
                'name' => 'gateway-1',
                'host' => '10.6.0.2',
                'wireguard_address' => '10.6.0.2',
                'orbit_path' => '/home/orbit/orbit',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
            ]);

        $caDir = "{$this->tempConfigRoot}/ca";
        file_put_contents("{$caDir}/root.crt", 'FAKE ROOT');
        file_put_contents("{$caDir}/root.key", 'dummy-key');

        $response = $this->getJson('/api/ca/root');

        $response
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'gateway_unavailable')
            ->assertJsonPath('error.meta.reason', 'ca_not_bootstrapped');
    });
});
