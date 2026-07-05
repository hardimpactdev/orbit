<?php

declare(strict_types=1);

use App\Services\Ca\OrbitCaService;
use App\Services\Ca\OrbitSiteCertificateInstaller;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    request()->headers->set(ExplicitRemoteShellFallback::HEADER, NodeTransportPreference::AgentPush->value);

    $storage = sys_get_temp_dir().'/orbit-site-cert-test-'.uniqid();
    mkdir($storage.'/app/orbit', 0777, true);
    app()->useStoragePath($storage);
    putenv("ORBIT_SITE_CERTIFICATE_TEST_STORAGE={$storage}");
    Process::swap(new Factory);

    createTestGatewayNode([
        'name' => 'gateway',
        'host' => '10.6.0.1',
        'orbit_path' => '/home/orbit/orbit',
    ]);
});

afterEach(function (): void {
    request()->headers->remove(ExplicitRemoteShellFallback::HEADER);

    $storage = getenv('ORBIT_SITE_CERTIFICATE_TEST_STORAGE');

    if (is_string($storage) && is_dir($storage)) {
        File::deleteDirectory($storage);
    }

    putenv('ORBIT_SITE_CERTIFICATE_TEST_STORAGE');
});

it('installs Orbit CA leaf certificates into the node Orbit cert directory', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.94:9477/v1/commands' => site_certificate_install_agent_response(),
    ]);
    $appNode = createTestAppHostNode([
        'name' => 'app-1',
        'user' => 'deploy',
        'orbit_agent_capable' => true,
        'wireguard_address' => '10.44.0.94',
    ]);

    $paths = new OrbitSiteCertificateInstaller(site_certificate_test_ca())->ensureFor($appNode, 'cta.example.test');

    expect($paths)
        ->toBe([
            'cert' => '/home/deploy/.config/orbit/certs/cta.example.test.crt',
            'key' => '/home/deploy/.config/orbit/certs/cta.example.test.key',
        ]);

    Http::assertSent(
        fn (Request $request): bool => site_certificate_install_request_matches(
            request: $request,
            url: 'http://10.44.0.94:9477/v1/commands',
            expectedInput: [
                'cert_path' => '/home/deploy/.config/orbit/certs/cta.example.test.crt',
                'key_path' => '/home/deploy/.config/orbit/certs/cta.example.test.key',
                'cert' => 'test-cert',
                'key' => 'test-key',
                'owner' => 'deploy',
            ],
        ),
    );
});

it('grants the node service user ownership of site TLS material for Vite dev server readability', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.95:9477/v1/commands' => site_certificate_install_agent_response(),
    ]);
    $appNode = createTestAppHostNode([
        'name' => 'booster-node',
        'user' => 'nckrtl',
        'orbit_agent_capable' => true,
        'wireguard_address' => '10.44.0.95',
    ]);

    $paths = new OrbitSiteCertificateInstaller(site_certificate_test_ca())->ensureFor($appNode, 'booster.test');

    $certPath = '/home/nckrtl/.config/orbit/certs/booster.test.crt';
    $keyPath = '/home/nckrtl/.config/orbit/certs/booster.test.key';

    expect($paths)
        ->toBe([
            'cert' => $certPath,
            'key' => $keyPath,
        ]);

    Http::assertSent(
        fn (Request $request): bool => site_certificate_install_request_matches(
            request: $request,
            url: 'http://10.44.0.95:9477/v1/commands',
            expectedInput: [
                'cert_path' => $certPath,
                'key_path' => $keyPath,
                'cert' => 'test-cert',
                'key' => 'test-key',
                'owner' => 'nckrtl',
            ],
        ),
    );
});

function site_certificate_test_ca(): OrbitCaService
{
    return new readonly class extends OrbitCaService {
        public function issueLeaf(string $host, array $additionalSans = []): array
        {
            $certsDir = storage_path('app/orbit/certs');
            File::ensureDirectoryExists($certsDir);

            $certPath = "{$certsDir}/{$host}.crt";
            $keyPath = "{$certsDir}/{$host}.key";

            File::put($certPath, 'test-cert');
            File::put($keyPath, 'test-key');

            return ['cert' => $certPath, 'key' => $keyPath];
        }
    };
}

function site_certificate_install_agent_response(): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'site-certificate.install',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'cert_path' => '/remote/cert.crt',
                            'key_path' => '/remote/key.key',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => '0',
            ],
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $expectedInput
 */
function site_certificate_install_request_matches(Request $request, string $url, array $expectedInput): bool
{
    /** @var mixed $argv */
    $argv = $request['argv'];
    /** @var mixed $input */
    $input = json_decode((string) $request['input'], associative: true, flags: JSON_THROW_ON_ERROR);

    return (
        $request->url() === $url
        && $request['binary'] === 'orbit'
        && $request['operation_id'] === 'site-certificate.install'
        && is_array($argv)
        && ($argv[0] ?? null) === 'internal:site-certificate:install'
        && str_starts_with((string) ($argv[1] ?? ''), '--operation-token=')
        && ($argv[2] ?? null) === '--json'
        && $input === $expectedInput
    );
}
