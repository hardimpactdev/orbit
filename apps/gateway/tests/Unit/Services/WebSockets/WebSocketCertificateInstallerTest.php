<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Ca\OrbitCaService;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\WebSockets\WebSocketBackendName;
use App\Services\WebSockets\WebSocketCertificateInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    request()->headers->set(
        ExplicitRemoteShellFallback::HEADER,
        NodeTransportPreference::AgentPush->value,
    );

    $this->tempStorage = sys_get_temp_dir().'/orbit-websocket-cert-test-'.uniqid();
    mkdir($this->tempStorage.'/app/orbit', 0777, true);
    app()->useStoragePath($this->tempStorage);

    createTestGatewayNode([
        'name' => 'gateway',
        'host' => '10.6.0.1',
        'orbit_path' => '/home/orbit/orbit',
    ]);
});

afterEach(function (): void {
    if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
        File::deleteDirectory($this->tempStorage);
    }
});

it('installs backend TLS material into the host Orbit cert directory', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.44:9477/v1/commands' => websocket_certificate_install_agent_response(),
    ]);

    $node = createTestAppHostNode([
        'name' => 'app-dev-1',
        'managed' => true,
        'wireguard_address' => '10.6.0.44',
    ], role: 'websocket');
    $issued = new ArrayObject;
    $ca = new WebSocketCertificateInstallerTestCa($issued);

    $paths = new WebSocketCertificateInstaller($ca, new WebSocketBackendName)->ensureFor($node);

    expect($paths)
        ->toBe([
            'cert' => '/etc/orbit/certs/10.6.0.44.crt',
            'key' => '/etc/orbit/certs/10.6.0.44.key',
        ])
        ->and($issued->getArrayCopy())
        ->toBe([
            ['host' => '10.6.0.44', 'additional_sans' => ['10.6.0.44']],
        ]);

    Http::assertSent(
        fn (Request $request): bool => websocket_certificate_install_request_matches(
            request: $request,
            url: 'http://10.6.0.44:9477/v1/commands',
            expectedInput: [
                'cert_path' => '/etc/orbit/certs/10.6.0.44.crt',
                'key_path' => '/etc/orbit/certs/10.6.0.44.key',
                'cert' => 'certificate for 10.6.0.44',
                'key' => 'key for 10.6.0.44',
                'owner' => null,
            ],
        ),
    );
});

it('requires a WireGuard address before installing backend TLS material', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'wireguard_address' => '',
    ]);

    expect(
        fn () => new WebSocketCertificateInstaller(
            new WebSocketCertificateInstallerTestCa(new ArrayObject),
            new WebSocketBackendName,
        )->ensureFor($node),
    )
        ->toThrow(RuntimeException::class, 'The websocket backend requires a WireGuard address.');
});

it('resolves expected backend certificate paths without installing material', function (): void {
    $node = createTestAppHostNode([
        'name' => 'app-dev-1',
        'wireguard_address' => '10.6.0.45',
    ], role: 'websocket');

    $paths = new WebSocketCertificateInstaller(
        new WebSocketCertificateInstallerTestCa(new ArrayObject),
        new WebSocketBackendName,
    )->expectedPathsFor($node);

    expect($paths)
        ->toBe([
            'cert' => '/etc/orbit/certs/10.6.0.45.crt',
            'key' => '/etc/orbit/certs/10.6.0.45.key',
        ]);
});

it('rejects nodes without a backend WireGuard address', function (): void {
    $node = createTestAppHostNode(['wireguard_address' => ''], role: 'websocket');

    expect(
        fn () => new WebSocketCertificateInstaller(
            new WebSocketCertificateInstallerTestCa(new ArrayObject),
            new WebSocketBackendName,
        )->expectedPathsFor($node),
    )
        ->toThrow(RuntimeException::class, 'The websocket backend requires a WireGuard address.');
});

readonly class WebSocketCertificateInstallerTestCa extends OrbitCaService
{
    public function __construct(
        private ArrayObject $issued,
    ) {}

    /**
     * @param  list<string>  $additionalSans
     * @return array{cert: string, key: string}
     */
    public function issueLeaf(string $host, array $additionalSans = []): array
    {
        $this->issued->append([
            'host' => $host,
            'additional_sans' => $additionalSans,
        ]);

        $certsDir = storage_path('app/orbit/certs');
        File::ensureDirectoryExists($certsDir);

        $certPath = "{$certsDir}/{$host}.crt";
        $keyPath = "{$certsDir}/{$host}.key";

        File::put($certPath, "certificate for {$host}");
        File::put($keyPath, "key for {$host}");

        return ['cert' => $certPath, 'key' => $keyPath];
    }
}

function websocket_certificate_install_agent_response(): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'websocket-certificate.install',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'cert_path' => '/etc/orbit/certs/10.6.0.44.crt',
                            'key_path' => '/etc/orbit/certs/10.6.0.44.key',
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
function websocket_certificate_install_request_matches(Request $request, string $url, array $expectedInput): bool
{
    /** @var mixed $argv */
    $argv = $request['argv'];
    /** @var mixed $input */
    $input = json_decode((string) $request['input'], associative: true, flags: JSON_THROW_ON_ERROR);

    return (
        $request->url() === $url
        && $request['binary'] === 'orbit'
        && $request['operation_id'] === 'websocket-certificate.install'
        && is_array($argv)
        && ($argv[0] ?? null) === 'internal:site-certificate:install'
        && str_starts_with((string) ($argv[1] ?? ''), '--operation-token=')
        && ($argv[2] ?? null) === '--json'
        && $input === $expectedInput
    );
}
