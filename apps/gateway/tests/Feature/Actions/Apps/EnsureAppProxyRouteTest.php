<?php

declare(strict_types=1);

use App\Actions\Apps\EnsureAppProxyRoute;
use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Ca\OrbitCaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(OrbitCaService::class, new EnsureAppProxyRouteTestCa);
});

final class EnsureAppProxyRouteTestShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class EnsureAppProxyRouteTestCertificateInstaller implements SiteCertificateInstaller
{
    /** @var list<string> */
    public array $hosts = [];

    /**
     * @return array{cert: string, key: string}
     */
    public function ensureFor(Node $node, string $domain): array
    {
        $this->hosts[] = $domain;

        return $this->expectedPathsFor($node, $domain);
    }

    /**
     * @return array{cert: string, key: string}
     */
    public function expectedPathsFor(Node $node, string $domain): array
    {
        return [
            'cert' => "/home/orbit/.config/orbit/certs/{$domain}.crt",
            'key' => "/home/orbit/.config/orbit/certs/{$domain}.key",
        ];
    }
}

final readonly class EnsureAppProxyRouteTestCa extends OrbitCaService
{
    public function rootCert(): string
    {
        return 'fake-root-ca';
    }
}

it('creates a PHP app proxy route targeting the FrankenPHP runtime container', function (): void {
    $node = Node::factory()->appDev()->create(['tld' => 'test']);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'document_root' => 'public',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $shell = new EnsureAppProxyRouteTestShell;
    $certificates = new EnsureAppProxyRouteTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    app(EnsureAppProxyRoute::class)->handle($app);

    $route = ProxyRoute::query()->where('app_id', $app->id)->firstOrFail();
    $siteScript = collect($shell->scripts)
        ->first(fn (string $script): bool => str_contains($script, '/etc/caddy/sites/docs.test.caddy'));
    $caddySite = base64_decode((string) str((string) $siteScript)->match("/printf %s\\s+'([^']+)'/")->toString(), true);

    expect($route->domain)->toBe('docs.test')
        ->and($route->config['runtime_upstream'])->toBe('https://orbit-app-docs:8443')
        ->and($route->config['runtime_upstream_tls'])->toBe([
            'trusted_by_gateway_ca' => true,
            'ca_path' => '/etc/orbit/ca/root.crt',
            'server_name' => 'docs.test',
        ])
        ->and($route->config['php_socket'])->toBeNull()
        ->and($caddySite)->toContain('reverse_proxy https://orbit-app-docs:8443')
        ->and($caddySite)->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')
        ->and($caddySite)->toContain('tls_server_name docs.test')
        ->and($caddySite)->not->toContain('php_fastcgi')
        ->and($caddySite)->not->toContain('file_server');
});

it('creates a static app proxy route with file_server', function (): void {
    $node = Node::factory()->appDev()->create(['tld' => 'test']);
    $app = App::factory()->for($node, 'node')->static()->create([
        'name' => 'marketing',
        'document_root' => 'public',
    ]);

    $shell = new EnsureAppProxyRouteTestShell;
    $certificates = new EnsureAppProxyRouteTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    app(EnsureAppProxyRoute::class)->handle($app);

    $route = ProxyRoute::query()->where('app_id', $app->id)->firstOrFail();
    $siteScript = collect($shell->scripts)
        ->first(fn (string $script): bool => str_contains($script, '/etc/caddy/sites/marketing.test.caddy'));
    $caddySite = base64_decode((string) str((string) $siteScript)->match("/printf %s\\s+'([^']+)'/")->toString(), true);

    expect($route->domain)->toBe('marketing.test')
        ->and($route->config['runtime_upstream'])->toBeNull()
        ->and($route->config['php_socket'])->toBeNull()
        ->and($caddySite)->toContain('file_server')
        ->and($caddySite)->toContain("root * {$app->path}/public")
        ->and($caddySite)->not->toContain('php_fastcgi')
        ->and($caddySite)->not->toContain('reverse_proxy');
});
