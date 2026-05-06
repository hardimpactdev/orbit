<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Doctor\RunDoctorRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

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
});
