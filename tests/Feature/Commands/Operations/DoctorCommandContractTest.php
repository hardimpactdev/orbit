<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Doctor\RunDoctorRequest;
use App\Models\FirewallRule;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Platform\PlatformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(PlatformDetector::class, new class extends PlatformDetector
    {
        public function detectLocal(): string
        {
            return 'linux';
        }
    });
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createDoctorLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'platform' => 'linux',
        'environment' => $role === 'app' ? 'development' : null,
        'is_local' => true,
    ]);
}

describe('doctor command contract', function (): void {
    it('runs the node family locally for gateway callers', function (): void {
        createDoctorLocalNode('gateway');

        $exitCode = Artisan::call('doctor', ['--family' => ['node'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['mode'])->toBe('verify')
            ->and($payload['success']['data']['doctor']['scope']['families'])->toBe(['node'])
            ->and($payload['success']['data']['doctor']['summary']['issues'])->toBe(0);
    });

    it('returns drift failure when node probe reports issues', function (): void {
        createDoctorLocalNode('gateway')->update(['platform' => null]);

        $exitCode = Artisan::call('doctor', ['--family' => ['node'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and($payload['error']['data']['doctor']['healthy'])->toBeFalse()
            ->and($payload['error']['data']['doctor']['issues'][0]['family'])->toBe('node');
    });

    it('rejects mutually exclusive fix and adopt modes before probes', function (): void {
        createDoctorLocalNode('gateway');

        $exitCode = Artisan::call('doctor', ['--fix' => true, '--adopt' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['fields'])->toBe(['fix', 'adopt']);
    });

    it('rejects unsupported families before probes', function (): void {
        createDoctorLocalNode('gateway');

        $exitCode = Artisan::call('doctor', ['--family' => ['cloudflare'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('scope_not_found')
            ->and($payload['error']['meta']['family'])->toBe('cloudflare');
    });

    it('denies app-node write modes before probes', function (): void {
        createDoctorLocalNode('app');

        $exitCode = Artisan::call('doctor', ['--fix' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($payload['error']['meta'])->toMatchArray([
                'caller_role' => 'app',
                'mode' => 'fix',
            ]);
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createDoctorLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            RunDoctorRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'doctor' => [
                            'healthy' => true,
                            'mode' => 'verify',
                            'scope' => ['families' => ['node'], 'node' => null, 'self' => false, 'app' => null, 'workspace' => null],
                            'summary' => ['issues' => 0, 'fixed' => 0, 'adopted' => 0, 'skipped' => 0, 'conflicts' => 0],
                            'issues' => [],
                            'actions' => [],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('doctor', ['--family' => ['node'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['healthy'])->toBeTrue();
    });

    it('runs the proxy family locally for gateway callers', function (): void {
        createDoctorLocalNode('gateway');

        $exitCode = Artisan::call('doctor', ['--family' => ['proxy'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['scope']['families'])->toBe(['proxy'])
            ->and($payload['success']['data']['doctor']['summary']['issues'])->toBe(0);
    });

    it('reports proxy family drift through the global doctor payload', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['upstream' => 'http://127.0.0.1:5173'],
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("0\t\t\t\t0\t0\n"));

        $exitCode = Artisan::call('doctor', ['--family' => ['proxy'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and($payload['error']['data']['doctor']['issues'][0])->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'proxy.route_missing',
                'kind' => 'missing',
            ]);
    });

    it('runs the firewall rule family locally for gateway callers', function (): void {
        createDoctorLocalNode('gateway')->update(['platform' => 'ubuntu']);

        $exitCode = Artisan::call('doctor', ['--family' => ['firewall_rule'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['scope']['families'])->toBe(['firewall_rule'])
            ->and($payload['success']['data']['doctor']['summary']['issues'])->toBe(0);
    });

    it('reports firewall rule family drift through the global doctor payload', function (): void {
        createDoctorLocalNode('gateway')->update(['platform' => 'ubuntu']);
        $appNode = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active', 'platform' => 'ubuntu']);
        FirewallRule::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'public-ssh',
            'direction' => 'incoming',
            'action' => 'allow',
            'source' => 'any',
            'destination' => null,
            'port' => '22',
            'protocol' => 'tcp',
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("Status: active\n\n     To                         Action      From\n     --                         ------      ----\n"));

        $exitCode = Artisan::call('doctor', ['--family' => ['firewall_rule'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and($payload['error']['data']['doctor']['issues'][0])->toMatchArray([
                'family' => 'firewall_rule',
                'node' => 'app-1',
                'key' => 'firewall_rule.baseline_conflict',
                'kind' => 'divergent',
            ]);
    });

    it('lets fix mode reach family dispatch and records unsupported actions as skipped', function (): void {
        createDoctorLocalNode('gateway')->update(['platform' => 'ubuntu']);
        $appNode = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active', 'platform' => 'ubuntu']);
        FirewallRule::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("Status: active\n\n     To                         Action      From\n     --                         ------      ----\n"));

        $exitCode = Artisan::call('doctor', ['--family' => ['firewall_rule'], '--fix' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and($payload['error']['data']['doctor']['mode'])->toBe('fix')
            ->and($payload['error']['data']['doctor']['summary']['skipped'])->toBe(1)
            ->and($payload['error']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'firewall_rule',
                'node' => 'app-1',
                'key' => 'firewall_rule.rule_missing',
                'mode' => 'fix',
                'status' => 'skipped',
            ]);
    });
});

final class DoctorProxyRemoteShell implements RemoteShell
{
    public function __construct(
        private readonly string $stdout,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: $this->stdout, stderr: '', durationMs: 1);
    }
}
