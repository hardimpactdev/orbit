<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Ca\OrbitCaService;
use App\Services\Ca\OrbitSiteCertificateInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tempStorage = sys_get_temp_dir().'/orbit-site-cert-test-'.uniqid();
    mkdir($this->tempStorage.'/app/orbit', 0777, true);
    app()->useStoragePath($this->tempStorage);
    Process::swap(new Factory);

    Node::create([
        'name' => 'gateway',
        'role' => 'gateway',
        'status' => 'active',
        'is_local' => true,
        'host' => '10.6.0.1',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
    ]);
});

afterEach(function (): void {
    if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
        File::deleteDirectory($this->tempStorage);
    }
});

it('installs Orbit CA leaf certificates into the node Orbit cert directory', function (): void {
    $appNode = Node::factory()->create([
        'name' => 'app-1',
        'ssh_user' => 'nckrtl',
        'user' => 'deploy',
    ]);

    $ca = new OrbitCaService;
    $ca->ensureRootCa();
    $shell = new OrbitSiteCertificateInstallerTestShell;

    $paths = (new OrbitSiteCertificateInstaller($ca, $shell))->ensureFor($appNode, 'cta.example.test');

    expect($paths)->toBe([
        'cert' => '/home/deploy/.config/orbit/certs/cta.example.test.crt',
        'key' => '/home/deploy/.config/orbit/certs/cta.example.test.key',
    ])
        ->and($shell->scripts)->toHaveCount(1)
        ->and($shell->scripts[0])->toContain('install -d -m 0755 \'/home/deploy/.config/orbit/certs\'')
        ->and($shell->scripts[0])->toContain('chmod 0644 \'/home/deploy/.config/orbit/certs/cta.example.test.crt\'')
        ->and($shell->scripts[0])->toContain('chmod 0600 \'/home/deploy/.config/orbit/certs/cta.example.test.key\'');
});

final class OrbitSiteCertificateInstallerTestShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
